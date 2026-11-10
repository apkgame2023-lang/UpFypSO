<?php
/**
 * Y4APK ▸ Wallet (2.64): the owner's side of the wallet and the creator
 * program. Included by Y4APK_Wallet::render().
 */
defined('ABSPATH') || exit;

global $wpdb;
$W = 'Y4APK_Wallet';
$tabs = [
	'overview' => __('Overview', 'y4apk'),
	'payouts'  => __('Withdrawals', 'y4apk'),
	'creators' => __('Creators', 'y4apk'),
	'tips'     => __('Gifts', 'y4apk'),
	'history'  => __('History', 'y4apk'),
	'settings' => __('Settings', 'y4apk'),
];
$tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? sanitize_key($_GET['tab']) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
$s = $W::settings();
$now = time();
$W::settle();
$tw = $W::t('wallets');
$tl = $W::t('ledger');
$tt = $W::t('tips');
$tp = $W::t('payouts');
$tc = $W::t('dl_count');
$since = $now - 30 * DAY_IN_SECONDS;
$sum_credit = (int) $wpdb->get_var("SELECT SUM(credit) FROM $tw");
$sum_earned = (int) $wpdb->get_var("SELECT SUM(earned) FROM $tw");
$wait = $wpdb->get_row("SELECT COUNT(*) AS n, SUM(amount) AS a FROM $tp WHERE status = 'requested'");
$tips30 = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS n, SUM(amount) AS a, SUM(CASE WHEN to_id = 0 THEN amount ELSE amount - net END) AS kept FROM $tt WHERE made > %d", $since));
$top30 = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(credit) FROM $tl WHERE kind = 'topup' AND made > %d", $since));
$dl30 = (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $tc WHERE day >= %s", wp_date('Y-m-d', $since)));
$who = sanitize_user(wp_unslash($_GET['who'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification
$notes = [
	'saved'     => __('Saved.', 'y4apk') . (function_exists('y4apk_cache_note') ? ' ' . y4apk_cache_note() : ''),
	'paid'      => __('Marked paid. The creator got an email.', 'y4apk'),
	'reject'    => __('Turned down. The money is back in the creator’s earnings, and they got an email.', 'y4apk'),
	'gone'      => __('That withdrawal was already handled.', 'y4apk'),
	'mode'      => __('Saved.', 'y4apk'),
	'nouser'    => __('No member with that name or email.', 'y4apk'),
	'noamount'  => __('Type an amount.', 'y4apk'),
	'adjusted'  => sprintf(__('Done: @%s’s money was changed.', 'y4apk'), $who),
	'tiphidden' => __('Saved.', 'y4apk'),
	'geo_saved'   => __('Saved.', 'y4apk'),
	'geo_csv'     => __('Read: the countries in the file are in the rates now.', 'y4apk'),
	'geo_fetched' => __('Saved, and Adsterra’s numbers by country are in.', 'y4apk'),
	'geo_err'     => (string) get_transient('y4apk_geo_err'),
];
$note = isset($_GET['note']) ? sanitize_key($_GET['note']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$form = function ($do, $tab) {
	echo '<input type="hidden" name="action" value="y4apk_wallet"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
	wp_nonce_field('y4apk_wallet_admin');
};
$member = function ($id) {
	$u = $id ? get_userdata((int) $id) : null;
	if (!$u) {
		return '<span class="y4ui-kpi-s">—</span>';
	}
	return '<a href="' . esc_url(Y4APK_Members::profile_url($u)) . '" target="_blank"><b>@' . esc_html($u->user_login) . '</b></a>';
};
?>
<div class="wrap y4ui y4wal">
	<?php Y4APK_Admin_UI::head([
		'icon'    => 'wallet',
		'tint'    => 'green',
		'title'   => esc_html__('Wallet and creators', 'y4apk'),
		'sub'     => esc_html__('Members buy coins in packs (crypto, like VIP) and spend them on VIP and gifts. Creators — members with enough followers, or ones you let in — earn from gifts and downloads, and ask to withdraw: you pay them by hand and mark it paid here.', 'y4apk'),
		'actions' => '<a class="button button-large" href="' . esc_url(Y4APK_Members::url('wallet')) . '" target="_blank">' . esc_html__('View the wallet page', 'y4apk') . '</a>',
	]); ?>
	<?php if ($note && isset($notes[$note])) : ?>
		<div class="notice <?php echo in_array($note, ['nouser', 'noamount', 'gone', 'geo_err'], true) ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($notes[$note]); ?></p></div>
	<?php endif; ?>

	<div class="y4ui-kpis">
		<a class="y4ui-kpi<?php echo (int) $wait->n ? ' is-red' : ''; ?>" href="<?php echo esc_url($W::admin_url(['tab' => 'payouts'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Withdrawals waiting', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n((int) $wait->n)); ?></span><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('%s to pay by hand', 'y4apk'), $W::fmt((int) $wait->a))); ?></span></a>
		<a class="y4ui-kpi" href="<?php echo esc_url($W::admin_url(['tab' => 'creators'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Owed to creators', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html($W::fmt($sum_earned)); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('earnings not withdrawn yet', 'y4apk'); ?></span></a>
		<a class="y4ui-kpi" href="<?php echo esc_url($W::admin_url(['tab' => 'history'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Members’ balances', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html($W::fmt($sum_credit)); ?></span><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('%s topped up in 30 days', 'y4apk'), $W::fmt($top30))); ?></span></a>
		<a class="y4ui-kpi is-green" href="<?php echo esc_url($W::admin_url(['tab' => 'tips'])); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Tips, 30 days', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html($W::fmt((int) $tips30->a)); ?></span><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('%1$s tips · the site kept %2$s', 'y4apk'), number_format_i18n((int) $tips30->n), $W::fmt((int) $tips30->kept))); ?></span></a>
	</div>

	<div class="y4ui-bar-row">
		<nav class="y4ui-tabs">
			<?php foreach ($tabs as $k => $label) : ?>
				<a href="<?php echo esc_url($W::admin_url(['tab' => $k])); ?>" class="<?php echo $tab === $k ? 'is-on' : ''; ?>"><?php echo esc_html($label); ?><?php if ($k === 'payouts' && (int) $wait->n) : ?> <span class="y4ui-chip is-red"><?php echo esc_html(number_format_i18n((int) $wait->n)); ?></span><?php endif; ?></a>
			<?php endforeach; ?>
		</nav>
	</div>

	<?php if ($tab === 'overview') :
		$recent = $wpdb->get_results("SELECT * FROM $tl ORDER BY id DESC LIMIT 25");
		?>
		<div class="y4ui-cols">
			<div class="y4ui-card">
				<div class="y4ui-card-h"><div><h2><?php esc_html_e('Latest money moves', 'y4apk'); ?></h2><p><?php esc_html_e('Every coin pack, gift, VIP bought with coins, day of downloads and withdrawal, newest first.', 'y4apk'); ?></p></div></div>
				<?php if (!$recent) : ?>
					<div class="y4ui-empty"><?php esc_html_e('Nothing yet. Once members buy coins or creators earn, it shows here.', 'y4apk'); ?></div>
				<?php else : ?>
				<div class="y4ui-scroll"><table class="y4ui-table">
					<thead><tr><th><?php esc_html_e('Member', 'y4apk'); ?></th><th><?php esc_html_e('What', 'y4apk'); ?></th><th><?php esc_html_e('Balance', 'y4apk'); ?></th><th><?php esc_html_e('Earnings', 'y4apk'); ?></th><th><?php esc_html_e('When', 'y4apk'); ?></th></tr></thead>
					<tbody>
					<?php foreach ($recent as $r) : ?>
						<tr><td><?php echo $member($r->user_id); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><?php echo esc_html($W::label($r)); ?></td>
							<td><?php echo (int) $r->credit ? esc_html(((int) $r->credit > 0 ? '+' : '') . $W::fmt($r->credit)) : '—'; ?></td>
							<td><?php echo (int) $r->earned ? esc_html(((int) $r->earned > 0 ? '+' : '') . $W::fmt($r->earned)) : '—'; ?></td>
							<td><span class="y4ui-kpi-s"><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $r->made)); ?></span></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php endif; ?>
			</div>
			<div class="y4ui-side">
				<div class="y4ui-card">
					<div class="y4ui-card-h"><div><h2><?php esc_html_e('How it works', 'y4apk'); ?></h2></div></div>
					<div class="y4ui-card-b">
						<ol class="y4wal-how">
							<li><?php esc_html_e('A member tops up once (crypto, through NOWPayments: set up in VIP ▸ Plans and settings). Small payments then work: VIP at any price, tips from 50¢.', 'y4apk'); ?></li>
							<li><?php echo esc_html(sprintf(__('Members with %s followers or more are creators (or ones you let in, in Creators): a Tip button shows on their profile and on the games and apps they share.', 'y4apk'), number_format_i18n((int) $s['followers']))); ?></li>
							<li><?php echo esc_html((float) $s['dl_rate'] > 0 ? sprintf(__('Each download of a creator’s file earns them %s per 1,000, counted once a day per device, and added the day after.', 'y4apk'), Y4APK_VIP::money($s['dl_rate'])) . (class_exists('Y4APK_Geo') && Y4APK_Geo::on() ? ' ' . __('That is the average: each country pays more or less, as Adsterra and Monetag pay there (Settings ▸ Pay by country).', 'y4apk') : '') : __('Downloads earn nothing until you set a rate per 1,000 in Settings.', 'y4apk')); ?></li>
							<li><?php esc_html_e('A creator asks to withdraw: you get an email, pay them by hand (Binance, TrueMoney…), then press Paid in Withdrawals. Turn one down and the money goes back to them.', 'y4apk'); ?></li>
						</ol>
						<p class="y4ui-note"><?php echo esc_html(sprintf(__('Downloads counted in 30 days: %s.', 'y4apk'), number_format_i18n($dl30))); ?></p>
					</div>
				</div>
			</div>
		</div>

	<?php elseif ($tab === 'payouts') :
		$open = $wpdb->get_results("SELECT * FROM $tp WHERE status = 'requested' ORDER BY id ASC LIMIT 100");
		$done = $wpdb->get_results("SELECT * FROM $tp WHERE status <> 'requested' ORDER BY id DESC LIMIT 50");
		?>
		<div class="y4ui-card">
			<div class="y4ui-card-h"><div><h2><?php esc_html_e('Waiting to be paid', 'y4apk'); ?></h2><p><?php esc_html_e('Send the money yourself (from your own Binance, wallet or account), then press Paid: add the transaction ID if you like, the creator sees it. The amount already left their earnings.', 'y4apk'); ?></p></div></div>
			<?php if (!$open) : ?>
				<div class="y4ui-empty"><?php esc_html_e('No withdrawals waiting.', 'y4apk'); ?></div>
			<?php else : ?>
			<div class="y4ui-scroll"><table class="y4ui-table">
				<thead><tr><th><?php esc_html_e('Creator', 'y4apk'); ?></th><th><?php esc_html_e('Amount', 'y4apk'); ?></th><th><?php esc_html_e('Pay to', 'y4apk'); ?></th><th><?php esc_html_e('Asked', 'y4apk'); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ($open as $p) : $d = json_decode(Y4APK_Mail::reveal((string) $p->details), true); $m = $W::methods()[$p->method] ?? [$p->method, '']; ?>
					<tr>
						<td><?php echo $member($p->user_id); // phpcs:ignore WordPress.Security.EscapeOutput ?><br><span class="y4ui-kpi-s">#<?php echo (int) $p->id; ?></span></td>
						<td><b style="font-size:15px"><?php echo esc_html($W::fmt($p->amount)); ?></b></td>
						<td><span class="y4ui-chip is-blue"><?php echo esc_html($m[0]); ?></span><br><code class="y4wal-to"><!--email_off--><?php echo esc_html(is_array($d) ? (string) ($d['to'] ?? '') : ''); ?><!--/email_off--></code></td>
						<td><span class="y4ui-kpi-s"><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $p->made)); ?></span></td>
						<td>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4wal-act">
								<?php $form('paid', 'payouts'); ?><input type="hidden" name="id" value="<?php echo (int) $p->id; ?>">
								<input type="text" name="note" placeholder="<?php esc_attr_e('Transaction ID or note (optional)', 'y4apk'); ?>">
								<button class="button button-primary" name="do" value="paid"><?php esc_html_e('Paid', 'y4apk'); ?></button>
								<button class="button-link button-link-delete" name="do" value="reject" onclick="var n=this.form.note;if(!n.value){n.value=prompt(<?php echo esc_attr(wp_json_encode(__('Why is it turned down? The creator sees this.', 'y4apk'))); ?>)||'';}return confirm(<?php echo esc_attr(wp_json_encode(__('Turn this withdrawal down? The money goes back to the creator’s earnings.', 'y4apk'))); ?>)"><?php esc_html_e('Turn down', 'y4apk'); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php endif; ?>
		</div>
		<?php if ($done) : ?>
		<div class="y4ui-card">
			<div class="y4ui-card-h"><div><h2><?php esc_html_e('Handled', 'y4apk'); ?></h2></div></div>
			<div class="y4ui-scroll"><table class="y4ui-table">
				<thead><tr><th><?php esc_html_e('Creator', 'y4apk'); ?></th><th><?php esc_html_e('Amount', 'y4apk'); ?></th><th><?php esc_html_e('By', 'y4apk'); ?></th><th><?php esc_html_e('State', 'y4apk'); ?></th><th><?php esc_html_e('Note', 'y4apk'); ?></th><th><?php esc_html_e('When', 'y4apk'); ?></th></tr></thead>
				<tbody>
				<?php foreach ($done as $p) : ?>
					<tr><td><?php echo $member($p->user_id); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><?php echo esc_html($W::fmt($p->amount)); ?></td><td><?php echo esc_html($W::methods()[$p->method][0] ?? $p->method); ?></td>
						<td><span class="y4ui-chip <?php echo $p->status === 'paid' ? 'is-green' : 'is-red'; ?>"><?php echo esc_html($p->status === 'paid' ? __('Paid', 'y4apk') : __('Turned down', 'y4apk')); ?></span></td>
						<td><?php echo esc_html($p->note); ?></td><td><span class="y4ui-kpi-s"><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $p->done)); ?></span></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
		</div>
		<?php endif; ?>

	<?php elseif ($tab === 'creators') :
		$need = max(0, (int) $s['followers']);
		$ids = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s GROUP BY meta_value HAVING COUNT(DISTINCT user_id) >= %d LIMIT 500", Y4APK_Social::FOLLOWS, max(1, $need)));
		$ids = array_merge($ids, $wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s", $W::MODE)), $wpdb->get_col("SELECT user_id FROM $tw WHERE earned > 0"));
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$rows = [];
		foreach ($ids as $id) {
			$u = get_userdata($id);
			if (!$u || !Y4APK_Members::is_member($u)) {
				continue;
			}
			$st = $W::creator($id);
			$rows[] = ['u' => $u, 'st' => $st, 'bal' => $W::balance($id),
				'dl' => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(n) FROM $tc WHERE user_id = %d AND day >= %s", $id, wp_date('Y-m-d', $since))),
				'all' => (int) $wpdb->get_var($wpdb->prepare("SELECT SUM(earned) FROM $tl WHERE user_id = %d AND earned > 0 AND kind IN ('tip_in', 'downloads')", $id))];
		}
		usort($rows, function ($a, $b) { return $b['st']['followers'] <=> $a['st']['followers']; });
		?>
		<div class="y4ui-cols">
			<div class="y4ui-card">
				<div class="y4ui-card-h"><div><h2><?php esc_html_e('Creators', 'y4apk'); ?></h2><p><?php echo esc_html(sprintf(__('Members with %s followers or more, ones you let in or kept out, and anyone with earnings.', 'y4apk'), number_format_i18n($need))); ?></p></div></div>
				<?php if (!$rows) : ?>
					<div class="y4ui-empty"><?php esc_html_e('No creators yet. Let a member in on the right, or lower the followers needed in Settings.', 'y4apk'); ?></div>
				<?php else : ?>
				<div class="y4ui-scroll"><table class="y4ui-table">
					<thead><tr><th><?php esc_html_e('Member', 'y4apk'); ?></th><th><?php esc_html_e('Followers', 'y4apk'); ?></th><th><?php esc_html_e('Downloads, 30 days', 'y4apk'); ?></th><th><?php esc_html_e('Earned in all', 'y4apk'); ?></th><th><?php esc_html_e('To withdraw', 'y4apk'); ?></th><th><?php esc_html_e('Earning', 'y4apk'); ?></th></tr></thead>
					<tbody>
					<?php foreach ($rows as $r) : ?>
						<tr>
							<td><?php echo $member($r['u']->ID); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
							<td><?php echo esc_html(number_format_i18n($r['st']['followers'])); ?></td>
							<td><?php echo esc_html(number_format_i18n($r['dl'])); ?></td>
							<td><?php echo esc_html($W::fmt($r['all'])); ?></td>
							<td><b><?php echo esc_html($W::fmt($r['bal']['earned'])); ?></b></td>
							<td>
								<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4wal-act">
									<?php $form('mode', 'creators'); ?><input type="hidden" name="user" value="<?php echo (int) $r['u']->ID; ?>">
									<select name="mode" onchange="this.form.submit()">
										<option value="" <?php selected($r['st']['mode'], ''); ?>><?php echo esc_html($r['st']['followers'] >= $need ? __('Yes, by followers', 'y4apk') : __('Not yet, by followers', 'y4apk')); ?></option>
										<option value="on" <?php selected($r['st']['mode'], 'on'); ?>><?php esc_html_e('Yes, let in', 'y4apk'); ?></option>
										<option value="off" <?php selected($r['st']['mode'], 'off'); ?>><?php esc_html_e('No, kept out', 'y4apk'); ?></option>
									</select>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php endif; ?>
			</div>
			<div class="y4ui-side">
				<div class="y4ui-card">
					<div class="y4ui-card-h"><div><h2><?php esc_html_e('Let a member in', 'y4apk'); ?></h2><p><?php esc_html_e('A creator before they have the followers: they earn from tips and downloads at once.', 'y4apk'); ?></p></div></div>
					<form class="y4ui-card-b y4ui-fields" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php $form('creator', 'creators'); ?>
						<div class="y4ui-field"><label for="wal-who"><?php esc_html_e('Member', 'y4apk'); ?></label><input type="text" id="wal-who" name="who" placeholder="@name" required></div>
						<button class="button button-primary"><?php esc_html_e('Let in', 'y4apk'); ?></button>
					</form>
				</div>
			</div>
		</div>

	<?php elseif ($tab === 'tips') :
		$list = $wpdb->get_results("SELECT * FROM $tt ORDER BY id DESC LIMIT 100");
		?>
		<div class="y4ui-card">
			<div class="y4ui-card-h"><div><h2><?php esc_html_e('Gifts', 'y4apk'); ?></h2><p><?php esc_html_e('To the site and to creators. Gifts to the site show with their name and message under “Thanks to” on the wallet page: hide any you do not want there.', 'y4apk'); ?></p></div></div>
			<?php if (!$list) : ?>
				<div class="y4ui-empty"><?php esc_html_e('No tips yet.', 'y4apk'); ?></div>
			<?php else : ?>
			<div class="y4ui-scroll"><table class="y4ui-table">
				<thead><tr><th><?php esc_html_e('From', 'y4apk'); ?></th><th><?php esc_html_e('To', 'y4apk'); ?></th><th><?php esc_html_e('Amount', 'y4apk'); ?></th><th><?php esc_html_e('Name and message', 'y4apk'); ?></th><th><?php esc_html_e('Paid by', 'y4apk'); ?></th><th><?php esc_html_e('When', 'y4apk'); ?></th><th></th></tr></thead>
				<tbody>
				<?php foreach ($list as $t) : ?>
					<tr<?php echo $t->hidden ? ' style="opacity:.55"' : ''; ?>>
						<td><?php echo $member($t->from_id); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><?php echo (int) $t->to_id ? $member($t->to_id) : '<span class="y4ui-chip is-green">' . esc_html__('The site', 'y4apk') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
						<td><b><?php echo esc_html(trim($W::gift_label($t->gift ?? '') . ' ' . $W::fmt($t->amount))); ?></b><?php if ((int) $t->to_id) : ?><br><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('%s to the creator', 'y4apk'), $W::fmt($t->net))); ?></span><?php endif; ?></td>
						<td><?php echo esc_html($t->name !== '' ? $t->name : __('Anonymous', 'y4apk')); ?><?php if ($t->msg !== '') : ?><br><span class="y4ui-kpi-s">“<?php echo esc_html($t->msg); ?>”</span><?php endif; ?></td>
						<td><?php echo esc_html($t->via === 'crypto' ? __('Crypto', 'y4apk') : __('Coins', 'y4apk')); ?></td>
						<td><span class="y4ui-kpi-s"><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $t->made)); ?></span></td>
						<td><?php if (!(int) $t->to_id) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('hide_tip', 'tips'); ?><input type="hidden" name="id" value="<?php echo (int) $t->id; ?>"><button class="button-link"><?php echo esc_html($t->hidden ? __('Show', 'y4apk') : __('Hide', 'y4apk')); ?></button></form><?php endif; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<?php endif; ?>
		</div>

	<?php elseif ($tab === 'history') :
		$fu = $who !== '' ? get_user_by('login', $who) : null;
		$list = $fu ? $wpdb->get_results($wpdb->prepare("SELECT * FROM $tl WHERE user_id = %d ORDER BY id DESC LIMIT 200", $fu->ID)) : $wpdb->get_results("SELECT * FROM $tl ORDER BY id DESC LIMIT 100");
		$fb = $fu ? $W::balance($fu->ID) : null;
		?>
		<div class="y4ui-cols">
			<div class="y4ui-card">
				<div class="y4ui-card-h">
					<div><h2><?php echo esc_html($fu ? sprintf(__('@%s’s history', 'y4apk'), $fu->user_login) : __('History', 'y4apk')); ?></h2><p><?php echo esc_html($fb ? sprintf(__('Balance %1$s · earnings %2$s', 'y4apk'), $W::fmt($fb['credit']), $W::fmt($fb['earned'])) : __('Every member’s money moves, newest first.', 'y4apk')); ?></p></div>
					<form method="get" class="y4wal-act"><input type="hidden" name="page" value="<?php echo esc_attr($W::SLUG); ?>"><input type="hidden" name="tab" value="history"><input type="text" name="who" value="<?php echo esc_attr($who); ?>" placeholder="<?php esc_attr_e('username', 'y4apk'); ?>"><button class="button"><?php esc_html_e('Show', 'y4apk'); ?></button></form>
				</div>
				<?php if (!$list) : ?>
					<div class="y4ui-empty"><?php esc_html_e('Nothing yet.', 'y4apk'); ?></div>
				<?php else : ?>
				<div class="y4ui-scroll"><table class="y4ui-table">
					<thead><tr><th><?php esc_html_e('Member', 'y4apk'); ?></th><th><?php esc_html_e('What', 'y4apk'); ?></th><th><?php esc_html_e('Balance', 'y4apk'); ?></th><th><?php esc_html_e('Earnings', 'y4apk'); ?></th><th><?php esc_html_e('When', 'y4apk'); ?></th></tr></thead>
					<tbody>
					<?php foreach ($list as $r) : ?>
						<tr><td><?php echo $member($r->user_id); // phpcs:ignore WordPress.Security.EscapeOutput ?></td><td><?php echo esc_html($W::label($r)); ?></td>
							<td><?php echo (int) $r->credit ? esc_html(((int) $r->credit > 0 ? '+' : '') . $W::fmt($r->credit)) : '—'; ?></td>
							<td><?php echo (int) $r->earned ? esc_html(((int) $r->earned > 0 ? '+' : '') . $W::fmt($r->earned)) : '—'; ?></td>
							<td><span class="y4ui-kpi-s"><?php echo esc_html(wp_date(get_option('date_format') . ' H:i', (int) $r->made)); ?></span></td></tr>
					<?php endforeach; ?>
					</tbody>
				</table></div>
				<?php endif; ?>
			</div>
			<div class="y4ui-side">
				<div class="y4ui-card">
					<div class="y4ui-card-h"><div><h2><?php esc_html_e('Add or take money', 'y4apk'); ?></h2><p><?php esc_html_e('For a payment taken outside the site (TrueMoney, a bank transfer…), a refund or a correction. A minus takes it off.', 'y4apk'); ?></p></div></div>
					<form class="y4ui-card-b y4ui-fields" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php $form('adjust', 'history'); ?>
						<div class="y4ui-field"><label for="adj-who"><?php esc_html_e('Member', 'y4apk'); ?></label><input type="text" id="adj-who" name="who" value="<?php echo esc_attr($who !== '' ? '@' . $who : ''); ?>" placeholder="@name" required></div>
						<div class="y4ui-field"><label for="adj-amount"><?php esc_html_e('Amount', 'y4apk'); ?></label><input type="text" id="adj-amount" name="amount" inputmode="decimal" placeholder="10 or -10" required style="width:140px"></div>
						<div class="y4ui-field"><label for="adj-which"><?php esc_html_e('Into', 'y4apk'); ?></label><select id="adj-which" name="which"><option value="credit"><?php echo esc_html(sprintf(__('%1$s (the amount in dollars: 1 = %2$s coins)', 'y4apk'), $W::coin_name(), number_format_i18n($W::coins_per()))); ?></option><option value="earned"><?php esc_html_e('Earnings (can be withdrawn)', 'y4apk'); ?></option></select></div>
						<div class="y4ui-field"><label for="adj-why"><?php esc_html_e('Note the member sees', 'y4apk'); ?></label><input type="text" id="adj-why" name="why" maxlength="120" placeholder="<?php esc_attr_e('e.g. Paid by TrueMoney', 'y4apk'); ?>"></div>
						<button class="button button-primary"><?php esc_html_e('Save', 'y4apk'); ?></button>
					</form>
				</div>
			</div>
		</div>

	<?php else : // settings ?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php $form('settings', 'settings'); ?>
			<div class="y4ui-cols">
				<div>
					<div class="y4ui-card">
						<div class="y4ui-card-h"><div><h2><?php esc_html_e('Coins and gifts', 'y4apk'); ?></h2><p><?php esc_html_e('Members buy coins in packs, then spend them on VIP and on gifts to creators. Coins have no cash value and are never paid out.', 'y4apk'); ?></p></div></div>
						<div class="y4ui-card-b y4ui-fields">
							<label class="y4ui-opt"><input type="checkbox" name="on" value="1" <?php checked($s['on']); ?>><span><b><?php esc_html_e('The wallet is on', 'y4apk'); ?></b><small><?php esc_html_e('Off: no buying coins, no gifts, no new withdrawals; coins and earnings are kept.', 'y4apk'); ?></small></span></label>
							<div class="y4ui-field"><label for="ws-coin"><?php esc_html_e('Name of the coins', 'y4apk'); ?></label><input type="text" id="ws-coin" name="coin_name" maxlength="24" value="<?php echo esc_attr($s['coin_name']); ?>" style="width:220px"></div>
							<div class="y4ui-field"><label for="ws-per"><?php esc_html_e('Coins in one dollar', 'y4apk'); ?></label><input type="number" id="ws-per" name="coins_per" min="1" max="1000" value="<?php echo esc_attr($s['coins_per']); ?>" style="width:120px"><p class="description"><?php esc_html_e('Sets what VIP costs in coins and what a gift is worth to a creator. Changing it changes what everyone’s coins are worth: set it once.', 'y4apk'); ?></p></div>
							<div class="y4ui-field"><label for="ws-packs"><?php esc_html_e('Coin packs (price=coins)', 'y4apk'); ?></label><input type="text" id="ws-packs" name="packs" value="<?php echo esc_attr($s['packs']); ?>"><p class="description"><?php echo esc_html(sprintf(__('e.g. 20=2100, 50=5500, 100=12000: a $20 pack gives 2,100 coins (5%% more). Any other amount gets the rate of the biggest pack it reaches ($35: +5%%). Up to 6; ones under the smallest amount (%s) are left out.', 'y4apk'), Y4APK_VIP::money($s['min_topup']))); ?></p></div>
							<div class="y4ui-field"><label for="ws-min"><?php esc_html_e('Smallest amount to buy', 'y4apk'); ?></label><input type="text" id="ws-min" name="min_topup" value="<?php echo esc_attr($s['min_topup']); ?>" style="width:120px"><p class="description"><?php esc_html_e('Members type any amount from here (or pick a pack). At least the payment company’s smallest payment for the crypto people use (VIP ▸ Payments ▸ Check minimums).', 'y4apk'); ?></p></div>
							<div class="y4ui-field"><label><?php esc_html_e('Gifts (coins each; 0 hides one)', 'y4apk'); ?></label>
								<span style="display:flex;flex-wrap:wrap;gap:10px 16px">
								<?php foreach ($W::GIFTS as $k => $icon) : ?>
									<label style="display:inline-flex;align-items:center;gap:6px"><span style="font-size:20px"><?php echo esc_html($icon); ?></span><input type="number" name="gift_<?php echo esc_attr($k); ?>" min="0" value="<?php echo esc_attr((int) ($s['gifts'][$k] ?? 0)); ?>" style="width:90px" aria-label="<?php echo esc_attr($W::gift_label($k)); ?>"></label>
								<?php endforeach; ?>
								</span>
							</div>
							<div class="y4ui-field"><label for="ws-day"><?php esc_html_e('Coins one member may give a day', 'y4apk'); ?></label><input type="number" id="ws-day" name="gift_day" min="0" value="<?php echo esc_attr($s['gift_day']); ?>" style="width:120px"><p class="description"><?php esc_html_e('Over 24 hours. 0: no limit. Gifts to creators are also refused from accounts less than a day old and from the creator’s own connection.', 'y4apk'); ?></p></div>
						</div>
					</div>
				</div>
				<div class="y4ui-side">
					<div class="y4ui-card">
						<div class="y4ui-card-h"><div><h2><?php esc_html_e('Creators', 'y4apk'); ?></h2></div></div>
						<div class="y4ui-card-b y4ui-fields">
							<div class="y4ui-field"><label for="ws-fol"><?php esc_html_e('Followers needed', 'y4apk'); ?></label><input type="number" id="ws-fol" name="followers" min="0" value="<?php echo esc_attr($s['followers']); ?>" style="width:120px"></div>
							<div class="y4ui-field"><label for="ws-share"><?php esc_html_e('The site’s share of a gift to a creator', 'y4apk'); ?></label><span><input type="text" id="ws-share" name="share" value="<?php echo esc_attr($s['share']); ?>" style="width:70px"> %</span><p class="description"><?php echo esc_html(sprintf(__('A 500-coin gift gives the creator %s.', 'y4apk'), $W::fmt((int) floor($W::from_coins(500) * (100 - (float) $s['share']) / 100)))); ?></p></div>
							<div class="y4ui-field"><label for="ws-hold"><?php esc_html_e('Days a gift waits before it can be withdrawn', 'y4apk'); ?></label><input type="number" id="ws-hold" name="hold_days" min="0" max="90" value="<?php echo esc_attr($s['hold_days']); ?>" style="width:120px"><p class="description"><?php esc_html_e('Time to catch stolen cards and charge-backs before money leaves. Download earnings are not held.', 'y4apk'); ?></p></div>
							<div class="y4ui-field"><label for="ws-rate"><?php esc_html_e('Paid per 1,000 downloads (average)', 'y4apk'); ?></label><input type="text" id="ws-rate" name="dl_rate" value="<?php echo esc_attr($s['dl_rate']); ?>" style="width:120px"><p class="description"><?php esc_html_e('Of a creator’s games and apps, once a day per device. 0: downloads earn nothing (tips still work). A change applies from the day not yet added.', 'y4apk'); ?></p></div>
							<div class="y4ui-field"><label for="ws-minout"><?php esc_html_e('Smallest withdrawal', 'y4apk'); ?></label><input type="text" id="ws-minout" name="min_payout" value="<?php echo esc_attr($s['min_payout']); ?>" style="width:120px"></div>
							<div class="y4ui-field"><label><?php esc_html_e('Ways you pay creators', 'y4apk'); ?></label>
								<?php foreach ($W::methods() as $k => $m) : ?>
									<label class="y4ui-opt"><input type="checkbox" name="methods[]" value="<?php echo esc_attr($k); ?>" <?php checked(in_array($k, (array) $s['methods'], true)); ?>><span><b><?php echo esc_html($m[0]); ?></b><small><?php echo esc_html($m[1]); ?></small></span></label>
								<?php endforeach; ?>
								<p class="description"><?php esc_html_e('You send each one by hand, from your own account: the creator only sees the site’s name.', 'y4apk'); ?></p>
							</div>
						</div>
					</div>
				</div>
			</div>
			<div class="y4ui-savebar"><span class="y4ui-hint"><?php esc_html_e('Prices and earnings are in the VIP page’s currency.', 'y4apk'); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></div>
		</form>
		<?php if (class_exists('Y4APK_Geo')) : // 2.67.1
			$gs = Y4APK_Geo::settings();
			$gd = Y4APK_Geo::data();
			$gr = Y4APK_Geo::rates();
			$gerr = get_option('y4apk_geo_last_err');
			$base = (float) $s['dl_rate'];
			?>
		<div class="y4ui-card" id="geo" style="margin-top:16px">
			<div class="y4ui-card-h"><div><h2><?php esc_html_e('Pay by country', 'y4apk'); ?></h2><p><?php esc_html_e('Downloads from countries where ads pay more earn creators more, as Adsterra and Monetag pay you there. “Paid per 1,000 downloads” above stays the average: each country is that times how its CPM (both networks, the last 30 days) compares with all countries’. Members do not see the rates.', 'y4apk'); ?></p></div></div>
			<form class="y4ui-card-b" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
				<?php $form('geo', 'settings'); ?>
				<label class="y4ui-opt"><input type="checkbox" name="geo_on" value="1" <?php checked($gs['on']); ?>><span><b><?php esc_html_e('Pay each country its own rate', 'y4apk'); ?></b><small><?php esc_html_e('Off: every download earns the average. Countries are known from Cloudflare; only the two letters are kept.', 'y4apk'); ?></small></span></label>
				<div class="y4ui-fields" style="margin-top:12px">
					<div class="y4ui-field"><label for="geo-key"><?php esc_html_e('Adsterra API key', 'y4apk'); ?></label><input type="password" id="geo-key" name="adsterra_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr($gs['adsterra_key'] !== '' ? '•••••••••• ' . __('(saved: leave empty to keep)', 'y4apk') : ''); ?>" <?php disabled(defined('Y4APK_ADSTERRA_KEY')); ?>>
						<?php if ($gs['adsterra_key'] !== '' && !defined('Y4APK_ADSTERRA_KEY')) : ?><label style="margin-left:8px"><input type="checkbox" name="forget_key" value="1"> <?php esc_html_e('Remove it', 'y4apk'); ?></label><?php endif; ?>
						<p class="description"><?php esc_html_e('Adsterra ▸ Profile (top right) ▸ API ▸ Generate new token. Read once a day; or put Y4APK_ADSTERRA_KEY in wp-config.php.', 'y4apk'); ?></p></div>
					<div class="y4ui-field"><label for="geo-csv"><?php esc_html_e('Or a CSV by country', 'y4apk'); ?></label><span><select name="csv_net"><?php foreach (Y4APK_Geo::NETS as $k => $label) : ?><option value="<?php echo esc_attr($k); ?>"<?php selected($k, 'monetag'); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?></select> <input type="file" id="geo-csv" name="csv" accept=".csv,text/csv"></span>
						<p class="description"><?php esc_html_e('Monetag ▸ Statistics ▸ the last 30 days ▸ Country ▸ Export to CSV (Adsterra: Statistics ▸ Group by Country ▸ Export CSV). Upload again now and then: it replaces that network’s numbers.', 'y4apk'); ?></p></div>
					<div class="y4ui-field"><label><?php esc_html_e('Lowest and highest', 'y4apk'); ?></label><span><input type="text" name="low" value="<?php echo esc_attr($gs['low']); ?>" style="width:70px"> × … <input type="text" name="high" value="<?php echo esc_attr($gs['high']); ?>" style="width:70px"> × <?php esc_html_e('the average', 'y4apk'); ?></span></div>
					<div class="y4ui-field"><label><?php esc_html_e('Impressions before a country gets its full rate', 'y4apk'); ?></label><input type="number" name="enough" min="100" value="<?php echo esc_attr($gs['enough']); ?>" style="width:120px"><p class="description"><?php esc_html_e('With fewer, its rate stays closer to the average (a few lucky impressions do not make it 4×).', 'y4apk'); ?></p></div>
				</div>
				<p class="y4ui-note"><?php foreach (Y4APK_Geo::NETS as $k => $label) {
					echo '<b>' . esc_html($label) . ':</b> ' . esc_html(!empty($gd[$k]) ? sprintf(__('%1$s countries, %2$s impressions, %3$s (%4$s)', 'y4apk'), number_format_i18n(count($gd[$k]['c'])), number_format_i18n(array_sum(array_column($gd[$k]['c'], 0))), $gd[$k]['how'] === 'api' ? __('from the API', 'y4apk') : __('from a CSV', 'y4apk'), sprintf(__('%s ago', 'y4apk'), human_time_diff((int) $gd[$k]['at']))) : __('nothing yet', 'y4apk')) . '<br>';
				} ?><?php if (is_array($gerr)) : ?><span style="color:#b32d2e"><?php echo esc_html(sprintf(__('Last daily read of Adsterra failed %1$s ago: %2$s', 'y4apk'), human_time_diff((int) $gerr['at']), $gerr['why'])); ?></span><?php endif; ?></p>
				<div class="y4ui-savebar"><span class="y4ui-hint"><label><input type="checkbox" name="fetch" value="1"> <?php esc_html_e('Read Adsterra’s numbers now', 'y4apk'); ?></label></span><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></div>
			</form>
			<?php if ($gr['c']) : ?>
			<div class="y4ui-scroll"><table class="y4ui-table">
				<thead><tr><th><?php esc_html_e('Country', 'y4apk'); ?></th><th><?php esc_html_e('Impressions', 'y4apk'); ?></th><th><?php esc_html_e('CPM', 'y4apk'); ?></th><th><?php esc_html_e('× average', 'y4apk'); ?></th><th><?php esc_html_e('Creators get per 1,000 downloads', 'y4apk'); ?></th></tr></thead>
				<tbody>
				<tr style="background:#f6f7f7"><td><b><?php esc_html_e('All countries (the average)', 'y4apk'); ?></b></td><td><?php echo esc_html(number_format_i18n($gr['imp'])); ?></td><td>$<?php echo esc_html(number_format_i18n($gr['avg'], 3)); ?></td><td>1.00×</td><td><b><?php echo esc_html(Y4APK_VIP::money($base)); ?></b></td></tr>
				<?php foreach (array_slice($gr['c'], 0, 40, true) as $cc => $v) : ?>
					<tr><td><?php echo esc_html(Y4APK_Geo::name($cc)); ?> <span class="y4ui-kpi-s"><?php echo esc_html($cc); ?></span></td><td><?php echo esc_html(number_format_i18n($v['imp'])); ?></td><td>$<?php echo esc_html(number_format_i18n($v['cpm'], 3)); ?></td>
						<td><span class="y4ui-chip <?php echo $v['x'] >= 1.25 ? 'is-green' : ($v['x'] <= .75 ? 'is-amber' : ''); ?>"><?php echo esc_html(number_format_i18n($v['x'], 2)); ?>×</span></td><td><b><?php echo esc_html(Y4APK_VIP::money(round($base * $v['x'], 2))); ?></b></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table></div>
			<p class="y4ui-note" style="padding:0 16px 14px"><?php esc_html_e('Countries not listed, and downloads whose country is not known, earn the average.', 'y4apk'); ?></p>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	<?php endif; ?>
	<style>.y4wal-act{display:flex;flex-wrap:wrap;align-items:center;gap:6px}.y4wal-act input[type=text]{width:220px;max-width:100%}.y4wal-to{display:inline-block;margin-top:4px;padding:2px 6px;border-radius:6px;background:#f6f7f7;font-size:12px;word-break:break-all;user-select:all}.y4wal-how{margin:0 0 0 18px;display:grid;gap:8px;line-height:1.55}</style>
</div>
