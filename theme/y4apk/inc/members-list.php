<?php
/**
 * Y4APK ▸ Members ▸ Accounts (2.45): every member account, 50 a page, with
 * whether they confirmed their email, when they joined and were last seen,
 * where they came from and on what, what they shared, and the things to do
 * with an account: send the confirmation email again, mark it confirmed, ban
 * or allow it, see its page. Filters (confirmed, not confirmed, banned, online,
 * new), search by name or email, sorting; many at once with the boxes, and
 * "send it to everyone not confirmed" in rounds of 50 (a mailbox sends only so
 * many a day). The queries go by role and user meta, a page at a time, so it
 * stays quick with many thousands of members. Settings are the second tab.
 */

defined('ABSPATH') || exit;

class Y4APK_Members_List {
	const SLUG  = 'y4apk-members';
	const ROUND = 50; // emails one "send to everyone" sends

	public static function init() {
		add_action('admin_post_y4apk_accounts', [__CLASS__, 'act']);
	}

	/** Y4APK ▸ Members: the accounts, or (tab=settings) what was there before. */
	public static function page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		// The settings' own answers come back here: show them where they were made
		$tab = (isset($_GET['tab']) && $_GET['tab'] === 'settings') || array_intersect(['saved', 'names', 'mail', 'checked', 'dropped'], array_keys($_GET)) ? 'settings' : 'accounts';
		$base = admin_url('admin.php?page=' . self::SLUG);
		echo '<nav class="nav-tab-wrapper y4m-tabs" style="margin:20px 20px 0 0"><a class="nav-tab' . ($tab === 'accounts' ? ' nav-tab-active' : '') . '" href="' . esc_url($base) . '">' . esc_html__('Accounts', 'y4apk') . '</a>'
			. '<a class="nav-tab' . ($tab === 'settings' ? ' nav-tab-active' : '') . '" href="' . esc_url(add_query_arg('tab', 'settings', $base)) . '">' . esc_html__('Settings, emails, privacy', 'y4apk') . '</a></nav>';
		if ($tab === 'settings') {
			Y4APK_Members_Admin::settings_page();
			return;
		}
		self::accounts();
	}

	/** Counts for the tiles and filters (kept 2 minutes). */
	private static function counts() {
		$c = get_transient('y4apk_accounts_counts');
		if (is_array($c)) {
			return $c;
		}
		global $wpdb;
		$cap = $wpdb->prefix . 'capabilities';
		$role = '%' . $wpdb->esc_like('"' . Y4APK_Members::ROLE . '"') . '%';
		$all = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value LIKE %s", $cap, $role));
		$meta = function ($key, $value, $op = '=') use ($wpdb, $cap, $role) {
			return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->usermeta} m JOIN {$wpdb->usermeta} c ON c.user_id = m.user_id AND c.meta_key = %s AND c.meta_value LIKE %s WHERE m.meta_key = %s AND m.meta_value $op %s",
				$cap, $role, $key, $value));
		};
		$c = [
			'all'     => $all,
			'pending' => $meta(Y4APK_Members::VERIFIED, '0'),
			'banned'  => $meta(Y4APK_Members::BANNED, '0', '<>'),
			'online'  => $meta(Y4APK_Social::SEEN, (string) (time() - Y4APK_Social::ONLINE), '>'),
			'new'     => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->users} u JOIN {$wpdb->usermeta} c ON c.user_id = u.ID AND c.meta_key = %s AND c.meta_value LIKE %s WHERE u.user_registered > %s", $cap, $role, gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS))),
		];
		$c['confirmed'] = max(0, $all - $c['pending']);
		set_transient('y4apk_accounts_counts', $c, 2 * MINUTE_IN_SECONDS);
		return $c;
	}

	private static function accounts() {
		$show = isset($_GET['show']) ? sanitize_key($_GET['show']) : '';
		$show = in_array($show, ['confirmed', 'pending', 'banned', 'online', 'new'], true) ? $show : '';
		$sort = isset($_GET['sort']) && in_array($_GET['sort'], ['new', 'old', 'seen', 'name'], true) ? $_GET['sort'] : 'new';
		$q = isset($_GET['q']) ? trim(sanitize_text_field(wp_unslash($_GET['q']))) : '';
		$paged = max(1, (int) ($_GET['paged'] ?? 1));
		$base = admin_url('admin.php?page=' . self::SLUG);
		$c = self::counts();

		$args = ['role' => Y4APK_Members::ROLE, 'number' => 50, 'paged' => $paged, 'count_total' => true, 'fields' => 'all'];
		$mq = [];
		if ($show === 'pending') {
			$mq[] = ['key' => Y4APK_Members::VERIFIED, 'value' => '0'];
		} elseif ($show === 'confirmed') {
			$mq[] = ['relation' => 'OR', ['key' => Y4APK_Members::VERIFIED, 'value' => '1'], ['key' => Y4APK_Members::VERIFIED, 'compare' => 'NOT EXISTS']];
		} elseif ($show === 'banned') {
			$mq[] = ['key' => Y4APK_Members::BANNED, 'value' => '0', 'compare' => '!='];
		} elseif ($show === 'online') {
			$mq[] = ['key' => Y4APK_Social::SEEN, 'value' => time() - Y4APK_Social::ONLINE, 'compare' => '>', 'type' => 'NUMERIC'];
		} elseif ($show === 'new') {
			$args['date_query'] = [['after' => gmdate('Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS), 'column' => 'user_registered']];
		}
		if ($mq) {
			$args['meta_query'] = $mq;
		}
		if ($q !== '') {
			$args['search'] = '*' . $q . '*';
			$args['search_columns'] = ['user_login', 'user_email', 'display_name'];
		}
		if ($sort === 'seen') {
			$args['meta_key'] = Y4APK_Social::SEEN;
			$args['orderby'] = 'meta_value_num';
			$args['order'] = 'DESC';
		} elseif ($sort === 'name') {
			$args['orderby'] = 'login';
			$args['order'] = 'ASC';
		} else {
			$args['orderby'] = 'registered';
			$args['order'] = $sort === 'old' ? 'ASC' : 'DESC';
		}
		$uq = new WP_User_Query($args);
		$users = $uq->get_results();
		$total = (int) $uq->get_total();
		$ids = array_map(function ($u) { return (int) $u->ID; }, $users);
		if ($ids) {
			update_meta_cache('user', $ids);
		}
		$posts = $ids ? count_many_users_posts($ids, 'post', true) : [];
		$here = add_query_arg(array_filter(['show' => $show, 'sort' => $sort !== 'new' ? $sort : '', 'q' => $q, 'paged' => $paged > 1 ? $paged : '']), $base);
		$msg = isset($_GET['did']) ? sanitize_key($_GET['did']) : '';
		$n = (int) ($_GET['n'] ?? 0);
		$msgs = ['sent' => _n('%s confirmation email sent.', '%s confirmation emails sent.', $n, 'y4apk'), 'confirmed' => _n('%s account marked as confirmed.', '%s accounts marked as confirmed.', $n, 'y4apk'),
			'banned' => _n('%s account banned.', '%s accounts banned.', $n, 'y4apk'), 'allowed' => _n('%s account allowed again.', '%s accounts allowed again.', $n, 'y4apk'), 'none' => __('Nothing to do: tick some accounts first.', 'y4apk'),
			'failed' => _n('%s email could not be sent. Check the mailbox in the Settings tab (Send myself a test email).', '%s emails could not be sent. Check the mailbox in the Settings tab (Send myself a test email).', $n, 'y4apk')];
		$tile = function ($label, $num, $sub, $key, $tone = '') use ($base, $show) {
			echo '<a class="y4m-tile' . ($tone ? ' is-' . esc_attr($tone) : '') . ($show === $key ? ' is-on' : '') . '" href="' . esc_url($key === '' ? $base : add_query_arg('show', $key, $base)) . '"><span>' . esc_html($label) . '</span><b>' . esc_html(number_format_i18n($num)) . '</b><small>' . esc_html($sub) . '</small></a>';
		};
		$pct = function ($a, $b) { return $b ? round($a / $b * 100) . '%' : '0%'; };
		?>
		<div class="wrap y4m">
			<div class="y4m-head">
				<div><h1><?php esc_html_e('Members', 'y4apk'); ?></h1><p><?php esc_html_e('Every account on the site: who confirmed their email, when they joined and were last seen, where they came from, what they shared.', 'y4apk'); ?></p></div>
				<?php if ($c['pending']) : ?>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm(<?php echo esc_attr(wp_json_encode(sprintf(__('Send the confirmation email again to up to %d members who have not confirmed (the ones not sent one today)?', 'y4apk'), self::ROUND))); ?>)">
					<input type="hidden" name="action" value="y4apk_accounts"><input type="hidden" name="do" value="send_all"><input type="hidden" name="back" value="<?php echo esc_attr($here); ?>"><?php wp_nonce_field('y4apk_accounts'); ?>
					<button class="button button-primary y4m-big"><span class="dashicons dashicons-email-alt"></span> <?php echo esc_html(sprintf(_n('Remind %s member to confirm', 'Remind %s members to confirm', min(self::ROUND, $c['pending']), 'y4apk'), number_format_i18n(min(self::ROUND, $c['pending'])))); ?></button>
				</form>
				<?php endif; ?>
			</div>
			<?php if ($msg && isset($msgs[$msg])) : ?><div class="notice notice-<?php echo $msg === 'none' ? 'warning' : ($msg === 'failed' ? 'error' : 'success'); ?> is-dismissible"><p><?php echo esc_html(sprintf($msgs[$msg], number_format_i18n($n))); ?></p></div><?php endif; ?>

			<div class="y4m-tiles">
				<?php
				$tile(__('All members', 'y4apk'), $c['all'], sprintf(__('%s new in 7 days', 'y4apk'), number_format_i18n($c['new'])), '');
				$tile(__('Email confirmed', 'y4apk'), $c['confirmed'], sprintf(__('%s of members', 'y4apk'), $pct($c['confirmed'], $c['all'])), 'confirmed', 'good');
				$tile(__('Not confirmed', 'y4apk'), $c['pending'], __('opened no link yet', 'y4apk'), 'pending', 'warn');
				$tile(__('Online now', 'y4apk'), $c['online'], __('in the last 3 minutes', 'y4apk'), 'online', 'blue');
				$tile(__('Banned', 'y4apk'), $c['banned'], __('cannot sign in', 'y4apk'), 'banned', 'bad');
				?>
			</div>
			<div class="y4m-bar-confirm" title="<?php echo esc_attr(sprintf(__('%1$s confirmed, %2$s not', 'y4apk'), number_format_i18n($c['confirmed']), number_format_i18n($c['pending']))); ?>"><i style="width:<?php echo esc_attr($c['all'] ? $c['confirmed'] / $c['all'] * 100 : 0); ?>%"></i></div>

			<form class="y4m-filters" method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
				<select name="show" onchange="this.form.submit()">
					<option value=""><?php esc_html_e('All members', 'y4apk'); ?></option>
					<option value="confirmed" <?php selected($show, 'confirmed'); ?>><?php esc_html_e('Email confirmed', 'y4apk'); ?></option>
					<option value="pending" <?php selected($show, 'pending'); ?>><?php esc_html_e('Not confirmed', 'y4apk'); ?></option>
					<option value="online" <?php selected($show, 'online'); ?>><?php esc_html_e('Online now', 'y4apk'); ?></option>
					<option value="new" <?php selected($show, 'new'); ?>><?php esc_html_e('New in 7 days', 'y4apk'); ?></option>
					<option value="banned" <?php selected($show, 'banned'); ?>><?php esc_html_e('Banned', 'y4apk'); ?></option>
				</select>
				<select name="sort" onchange="this.form.submit()">
					<option value="new" <?php selected($sort, 'new'); ?>><?php esc_html_e('Newest first', 'y4apk'); ?></option>
					<option value="old" <?php selected($sort, 'old'); ?>><?php esc_html_e('Oldest first', 'y4apk'); ?></option>
					<option value="seen" <?php selected($sort, 'seen'); ?>><?php esc_html_e('Last seen', 'y4apk'); ?></option>
					<option value="name" <?php selected($sort, 'name'); ?>><?php esc_html_e('Name A–Z', 'y4apk'); ?></option>
				</select>
				<input type="search" name="q" value="<?php echo esc_attr($q); ?>" placeholder="<?php esc_attr_e('Name or email…', 'y4apk'); ?>">
				<button class="button"><?php esc_html_e('Search', 'y4apk'); ?></button>
				<span class="y4m-total"><?php echo esc_html(sprintf(_n('%s account', '%s accounts', $total, 'y4apk'), number_format_i18n($total))); ?></span>
			</form>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-y4m>
				<input type="hidden" name="action" value="y4apk_accounts"><input type="hidden" name="back" value="<?php echo esc_attr($here); ?>"><?php wp_nonce_field('y4apk_accounts'); ?>
				<div class="y4m-bulk">
					<label><input type="checkbox" data-all> <?php esc_html_e('Select all', 'y4apk'); ?></label><span data-picked></span>
					<button class="button" name="do" value="send" data-needs><?php esc_html_e('Send confirmation email', 'y4apk'); ?></button>
					<button class="button" name="do" value="confirm" data-needs><?php esc_html_e('Mark as confirmed', 'y4apk'); ?></button>
					<button class="button" name="do" value="allow" data-needs><?php esc_html_e('Allow again', 'y4apk'); ?></button>
					<button class="button y4m-danger" name="do" value="ban" data-needs data-ask="<?php esc_attr_e('Ban the ticked accounts? Their waiting uploads are removed and their pictures taken down.', 'y4apk'); ?>"><?php esc_html_e('Ban', 'y4apk'); ?></button>
				</div>
				<div class="y4m-table-wrap">
				<table class="y4m-table">
					<thead><tr><th class="y4m-cb"></th><th><?php esc_html_e('Member', 'y4apk'); ?></th><th><?php esc_html_e('Email', 'y4apk'); ?></th><th><?php esc_html_e('Joined', 'y4apk'); ?></th><th><?php esc_html_e('Last seen', 'y4apk'); ?></th><th><?php esc_html_e('Came from', 'y4apk'); ?></th><th class="y4m-num"><?php esc_html_e('Shared', 'y4apk'); ?></th><th></th></tr></thead>
					<tbody>
					<?php if (!$users) : ?><tr><td colspan="8" class="y4m-empty"><?php esc_html_e('No accounts match.', 'y4apk'); ?></td></tr><?php endif; ?>
					<?php foreach ($users as $u) :
						$ver = get_user_meta($u->ID, Y4APK_Members::VERIFIED, true);
						$pending = $ver === '0';
						$banned = (bool) get_user_meta($u->ID, Y4APK_Members::BANNED, true);
						$seen = Y4APK_Social::seen_at($u);
						$online = Y4APK_Social::is_online($u);
						$sent = (int) get_user_meta($u->ID, 'y4apk_vsent', true);
						$o = class_exists('Y4APK_Insights') ? Y4APK_Insights::of($u->ID) : [];
						$src = class_exists('Y4APK_Insights') ? Y4APK_Insights::source_names() : [];
						$from = array_filter([!empty($o['cc']) ? Y4APK_Insights::country_name($o['cc']) : '', !empty($o['os']) ? $o['os'] : '', !empty($o['browser']) ? $o['browser'] : '']);
						$shared = (int) ($posts[$u->ID] ?? 0);
						?>
						<tr class="<?php echo $banned ? 'is-banned' : ''; ?>">
							<td class="y4m-cb"><input type="checkbox" name="users[]" value="<?php echo esc_attr($u->ID); ?>"></td>
							<td><div class="y4m-who"><?php echo Y4APK_Social::avatar($u, 36); ?><span><b><?php echo esc_html(Y4APK_Social::name($u)); ?></b><a href="<?php echo esc_url(Y4APK_Members::profile_url($u)); ?>" target="_blank" rel="noopener">@<?php echo esc_html($u->user_login); ?></a></span><?php if ($banned) : ?><em class="y4m-chip is-bad"><?php esc_html_e('Banned', 'y4apk'); ?></em><?php endif; ?></div></td>
							<td><div class="y4m-mail"><span><?php echo esc_html($u->user_email); ?></span>
								<?php if ($pending) : ?><em class="y4m-chip is-warn"><?php esc_html_e('Not confirmed', 'y4apk'); ?></em><?php if ($sent) : ?><small><?php echo esc_html(sprintf(__('reminded %s', 'y4apk'), Y4APK_Roblox::ago($sent))); ?></small><?php endif; ?>
								<?php else : ?><em class="y4m-chip is-good"><?php esc_html_e('Confirmed', 'y4apk'); ?></em><?php endif; ?></div></td>
							<td><span class="y4m-when" title="<?php echo esc_attr(get_date_from_gmt($u->user_registered, 'Y-m-d H:i')); ?>"><?php echo esc_html(mysql2date('j M Y', get_date_from_gmt($u->user_registered))); ?></span><small><?php echo esc_html(Y4APK_Roblox::ago(strtotime($u->user_registered . ' UTC'))); ?></small></td>
							<td><?php if ($online) : ?><span class="y4m-online"><i></i><?php esc_html_e('Online', 'y4apk'); ?></span><?php elseif ($seen) : ?><?php echo esc_html(Y4APK_Roblox::ago($seen)); ?><?php else : ?><span class="y4m-mute">—</span><?php endif; ?></td>
							<td><?php if (!empty($o['cc'])) : ?><span class="y4m-cc"><?php echo esc_html($o['cc']); ?></span><?php endif; ?><span class="y4m-from"><?php echo esc_html($from ? implode(' · ', array_slice($from, 1)) : ''); ?></span><?php if (!empty($o['source']) && isset($src[$o['source']])) : ?><small><?php echo esc_html($src[$o['source']]); ?></small><?php elseif (!$from) : ?><span class="y4m-mute"><?php esc_html_e('not recorded', 'y4apk'); ?></span><?php endif; ?></td>
							<td class="y4m-num"><?php echo esc_html(number_format_i18n($shared)); ?></td>
							<td class="y4m-acts">
								<?php if ($pending) : ?><button class="button button-small" name="one" value="<?php echo esc_attr('send|' . $u->ID); ?>" title="<?php esc_attr_e('Send the confirmation email again', 'y4apk'); ?>"><span class="dashicons dashicons-email-alt"></span></button>
								<button class="button button-small" name="one" value="<?php echo esc_attr('confirm|' . $u->ID); ?>" title="<?php esc_attr_e('Mark as confirmed', 'y4apk'); ?>"><span class="dashicons dashicons-yes"></span></button><?php endif; ?>
								<button type="button" class="button button-small" data-more="<?php echo (int) $u->ID; ?>" title="<?php esc_attr_e('Everything about this account', 'y4apk'); ?>"><span class="dashicons dashicons-ellipsis"></span></button>
							</td>
						</tr>
						<tr class="y4m-more" data-more-of="<?php echo (int) $u->ID; ?>" hidden><td></td><td colspan="7">
							<dl class="y4m-facts">
								<div><dt><?php esc_html_e('Public name', 'y4apk'); ?></dt><dd><?php echo esc_html(Y4APK_Social::name($u)); ?></dd></div>
								<div><dt><?php esc_html_e('Sign-in name', 'y4apk'); ?></dt><dd>@<?php echo esc_html($u->user_login); ?></dd></div>
								<div><dt><?php esc_html_e('Email', 'y4apk'); ?></dt><dd><?php echo esc_html($u->user_email); ?></dd></div>
								<div><dt><?php esc_html_e('Joined', 'y4apk'); ?></dt><dd><?php echo esc_html(get_date_from_gmt($u->user_registered, 'j M Y, H:i')); ?></dd></div>
								<div><dt><?php esc_html_e('Followers / following', 'y4apk'); ?></dt><dd><?php echo esc_html(number_format_i18n(Y4APK_Social::followers_count($u->ID)) . ' / ' . number_format_i18n(Y4APK_Social::following_count($u->ID))); ?></dd></div>
								<div><dt><?php esc_html_e('Live posts', 'y4apk'); ?></dt><dd><?php echo esc_html(number_format_i18n($shared)); ?></dd></div>
								<div><dt><?php esc_html_e('Country', 'y4apk'); ?></dt><dd><?php echo esc_html(!empty($o['cc']) ? Y4APK_Insights::country_name($o['cc']) . ($o['cc_by'] === 'lang' ? ' ' . __('(guessed from the language)', 'y4apk') : '') : '—'); ?></dd></div>
								<div><dt><?php esc_html_e('Device', 'y4apk'); ?></dt><dd><?php echo esc_html(implode(' · ', array_filter([$o['device'] ?? '', $o['os'] ?? '', $o['browser'] ?? '', ($o['app'] ?? '') === 'apk' ? 'Y4APK app' : (($o['app'] ?? '') === 'pwa' ? 'web app' : '')])) ?: '—'); ?></dd></div>
								<div><dt><?php esc_html_e('Came from', 'y4apk'); ?></dt><dd><?php echo esc_html(implode(' · ', array_filter([isset($src[$o['source'] ?? '']) && ($o['source'] ?? '') !== '' ? $src[$o['source']] : '', $o['ref'] ?? '', $o['land'] ?? ''])) ?: '—'); ?></dd></div>
								<div><dt><?php esc_html_e('Language', 'y4apk'); ?></dt><dd><?php echo esc_html(($o['lang'] ?? '') !== '' ? $o['lang'] : '—'); ?></dd></div>
								<div class="is-wide"><dt><?php esc_html_e('About', 'y4apk'); ?></dt><dd><?php echo esc_html(Y4APK_Social::bio($u) !== '' ? Y4APK_Social::bio($u) : '—'); ?></dd></div>
							</dl>
							<p class="y4m-more-acts"><a class="button button-small" href="<?php echo esc_url(Y4APK_Members::profile_url($u)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Public page', 'y4apk'); ?></a>
								<a class="button button-small" href="<?php echo esc_url(admin_url('edit.php?author=' . $u->ID)); ?>"><?php esc_html_e('Their posts', 'y4apk'); ?></a>
								<?php if ($banned) : ?><button class="button button-small" name="one" value="<?php echo esc_attr('allow|' . $u->ID); ?>"><?php esc_html_e('Allow again', 'y4apk'); ?></button>
								<?php else : ?><button class="button button-small y4m-danger" name="one" value="<?php echo esc_attr('ban|' . $u->ID); ?>" data-ask="<?php esc_attr_e('Ban this account? Their waiting uploads are removed and their pictures taken down.', 'y4apk'); ?>"><?php esc_html_e('Ban', 'y4apk'); ?></button><?php endif; ?></p>
						</td></tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</form>
			<?php $pages = (int) ceil($total / 50); if ($pages > 1) : ?>
			<div class="tablenav"><div class="tablenav-pages"><?php echo paginate_links(['base' => add_query_arg('paged', '%#%', remove_query_arg('paged', $here)), 'format' => '', 'current' => $paged, 'total' => $pages]); ?></div></div>
			<?php endif; ?>
		</div>
		<style>
		#wpcontent{padding-left:0}.y4m-tabs,.y4m{margin-left:20px!important;margin-right:20px!important}
		.y4m{--ink:#1d2327;--mute:#646970;--line:#e2e4e7;max-width:none}
		.y4m-head{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:12px 20px;margin:14px 0 16px}.y4m-head h1{margin:0;padding:0;font-size:26px;font-weight:700}.y4m-head p{margin:4px 0 0;color:var(--mute);max-width:720px}
		.y4m-big{display:inline-flex!important;align-items:center;gap:6px;height:38px!important;padding:0 16px!important;font-weight:600!important;border-radius:8px!important}
		.y4m-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px}
		.y4m-tile{position:relative;display:flex;flex-direction:column;gap:2px;padding:14px 16px;border-radius:14px;background:#fff;border:1px solid #dcdcde;text-decoration:none;color:inherit;transition:box-shadow .15s,border-color .15s}
		.y4m-tile:hover{border-color:#2271b1;color:inherit}.y4m-tile.is-on{border-color:#2271b1;box-shadow:0 0 0 2px #2271b1}
		.y4m-tile span{font-size:12px;font-weight:600;color:#3c434a}.y4m-tile b{font-size:28px;line-height:1.15;color:var(--ink)}.y4m-tile small{font-size:12px;color:var(--mute)}
		.y4m-tile::before{content:"";position:absolute;left:0;top:14px;bottom:14px;width:3px;border-radius:0 3px 3px 0;background:#c3c4c7}
		.y4m-tile.is-good::before{background:#0ca30c}.y4m-tile.is-warn::before{background:#fab219}.y4m-tile.is-bad::before{background:#d03b3b}.y4m-tile.is-blue::before{background:#2a78d6}
		.y4m-bar-confirm{height:8px;margin:12px 0 18px;border-radius:8px;background:#fdecc8;overflow:hidden}.y4m-bar-confirm i{display:block;height:100%;border-radius:8px;background:#0ca30c}
		.y4m-filters{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:10px}.y4m-filters input[type=search]{min-width:220px}.y4m-total{margin-left:auto;color:var(--mute);font-weight:600}
		.y4m-bulk{position:sticky;top:32px;z-index:3;display:flex;flex-wrap:wrap;align-items:center;gap:8px;padding:10px 12px;margin-bottom:10px;background:#fff;border:1px solid #dcdcde;border-radius:12px}.y4m-bulk label{font-weight:600;margin-right:4px}[data-picked]{color:var(--mute);margin-right:auto}
		.y4m-danger{color:#b32d2e!important;border-color:#e8a6a6!important}
		.y4m-table-wrap{overflow-x:auto;background:#fff;border:1px solid #dcdcde;border-radius:14px}
		.y4m-table{width:100%;border-collapse:collapse;min-width:900px}.y4m-table th{position:sticky;top:0;padding:10px 12px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:var(--mute);background:#f6f7f7;border-bottom:1px solid var(--line)}
		.y4m-table td{padding:10px 12px;border-bottom:1px solid #f0f0f1;vertical-align:middle}.y4m-table tr:last-child td{border-bottom:0}.y4m-table tbody tr:hover td{background:#fafbfc}.y4m-table tr.is-banned td{background:#fcf0f1}
		.y4m-cb{width:28px}.y4m-num{text-align:right;font-variant-numeric:tabular-nums}
		.y4m-who{display:flex;align-items:center;gap:10px;min-width:180px}.y4m-who .acc-av{flex:none;display:inline-grid;place-items:center;border-radius:50%;color:#fff;font-weight:700;object-fit:cover}.y4m-who span{display:flex;flex-direction:column;min-width:0}.y4m-who b{color:var(--ink)}.y4m-who a{font-size:12px;text-decoration:none}
		.y4m-mail{display:flex;flex-direction:column;align-items:flex-start;gap:3px}.y4m-mail span{overflow-wrap:anywhere}.y4m-mail small,.y4m-table td small{display:block;font-size:11px;color:var(--mute)}
		.y4m-chip{display:inline-block;padding:1px 8px;border-radius:99px;font-size:11px;font-style:normal;font-weight:700}.y4m-chip.is-good{color:#0a6b0a;background:#e3f5e3}.y4m-chip.is-warn{color:#7a4d00;background:#fff4de}.y4m-chip.is-bad{color:#a3262c;background:#fde5e6}
		.y4m-online{display:inline-flex;align-items:center;gap:6px;font-weight:600;color:#0a6b0a}.y4m-online i{width:8px;height:8px;border-radius:50%;background:#0ca30c;box-shadow:0 0 0 3px rgba(12,163,12,.2)}
		.y4m-cc{display:inline-block;margin-right:6px;padding:0 5px;border-radius:4px;font-size:10px;font-weight:700;color:#3c434a;background:#f0f0f1}.y4m-mute{color:#a7aaad}
		.y4m-acts{white-space:nowrap;text-align:right}.y4m-acts .button{padding:0 6px!important;min-height:28px}.y4m-acts .dashicons{font-size:16px;width:16px;height:16px;vertical-align:text-bottom}
		.y4m-more td{background:#f9fafb}.y4m-facts{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px 18px;margin:4px 0 10px}.y4m-facts .is-wide{grid-column:1/-1}.y4m-facts dt{font-size:11px;font-weight:700;color:var(--mute);text-transform:uppercase}.y4m-facts dd{margin:2px 0 0;overflow-wrap:anywhere}
		.y4m-more-acts{display:flex;flex-wrap:wrap;gap:6px;margin:0}.y4m-empty{padding:30px!important;text-align:center;color:var(--mute)}
		@media (max-width:782px){.y4m-tabs,.y4m{margin-left:10px!important;margin-right:10px!important}.y4m-bulk{top:46px}.y4m-total{margin-left:0}.y4m-filters input[type=search]{min-width:0;flex:1}}
		</style>
		<script>
		(function () {
			var f = document.querySelector('[data-y4m]'); if (!f) { return; }
			var boxes = function () { return [].slice.call(f.querySelectorAll('input[name="users[]"]')); };
			var out = f.querySelector('[data-picked]'), all = f.querySelector('[data-all]');
			var count = function () { var n = boxes().filter(function (b) { return b.checked; }).length; out.textContent = n ? <?php echo wp_json_encode(__('%s ticked', 'y4apk')); ?>.replace('%s', n) : ''; };
			all.addEventListener('change', function () { var on = this.checked; boxes().forEach(function (b) { b.checked = on; }); count(); });
			f.addEventListener('change', function (e) { if (e.target.name === 'users[]') { count(); } });
			f.addEventListener('click', function (e) {
				var m = e.target.closest('[data-more]'); if (m) { var r = f.querySelector('[data-more-of="' + m.getAttribute('data-more') + '"]'); r.hidden = !r.hidden; return; }
				var b = e.target.closest('button'); if (!b) { return; }
				if (b.hasAttribute('data-needs') && !boxes().some(function (x) { return x.checked; })) { e.preventDefault(); alert(<?php echo wp_json_encode(__('Tick at least one account first.', 'y4apk')); ?>); return; }
				if (b.hasAttribute('data-ask') && !confirm(b.getAttribute('data-ask'))) { e.preventDefault(); }
			});
		})();
		</script>
		<?php
	}

	/** The buttons: one account ("do|ID") or the ticked ones, or "send it to everyone not confirmed". */
	public static function act() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_accounts');
		$do = sanitize_key($_POST['do'] ?? '');
		$ids = array_map('absint', (array) ($_POST['users'] ?? []));
		if (isset($_POST['one']) && preg_match('/^(send|confirm|ban|allow)\|(\d+)$/', (string) wp_unslash($_POST['one']), $m)) {
			$do = $m[1];
			$ids = [(int) $m[2]];
		}
		if ($do === 'send_all') {
			$ids = get_users(['role' => Y4APK_Members::ROLE, 'fields' => 'ID', 'number' => self::ROUND, 'orderby' => 'registered', 'order' => 'DESC',
				'meta_query' => ['relation' => 'AND', ['key' => Y4APK_Members::VERIFIED, 'value' => '0'],
					['relation' => 'OR', ['key' => 'y4apk_vsent', 'compare' => 'NOT EXISTS'], ['key' => 'y4apk_vsent', 'value' => time() - DAY_IN_SECONDS, 'compare' => '<', 'type' => 'NUMERIC']]]]);
			$do = 'send';
		}
		$n = 0;
		$fail = 0;
		foreach (array_slice(array_filter($ids), 0, 200) as $id) {
			$u = get_userdata($id);
			if (!$u || !Y4APK_Members::is_member($u)) {
				continue;
			}
			if ($do === 'send' && get_user_meta($id, Y4APK_Members::VERIFIED, true) === '0') {
				Y4APK_Members::resend_verification($u) ? $n++ : $fail++;
			} elseif ($do === 'confirm' && get_user_meta($id, Y4APK_Members::VERIFIED, true) === '0') {
				update_user_meta($id, Y4APK_Members::VERIFIED, '1');
				delete_user_meta($id, 'y4apk_vtoken');
				$n++;
			} elseif ($do === 'ban' && !get_user_meta($id, Y4APK_Members::BANNED, true)) {
				update_user_meta($id, Y4APK_Members::BANNED, 1);
				Y4APK_Social::drop_images($id);
				WP_Session_Tokens::get_instance($id)->destroy_all();
				foreach (get_posts(['author' => $id, 'post_type' => 'post', 'post_status' => ['pending', 'draft'], 'numberposts' => -1, 'fields' => 'ids']) as $pid) {
					wp_trash_post($pid);
				}
				$n++;
			} elseif ($do === 'allow' && get_user_meta($id, Y4APK_Members::BANNED, true)) {
				delete_user_meta($id, Y4APK_Members::BANNED);
				$n++;
			}
		}
		delete_transient('y4apk_accounts_counts');
		$did = ['send' => 'sent', 'confirm' => 'confirmed', 'ban' => 'banned', 'allow' => 'allowed'];
		$back = esc_url_raw(wp_unslash($_POST['back'] ?? ''));
		$back = $back !== '' && strpos($back, admin_url()) === 0 ? $back : admin_url('admin.php?page=' . self::SLUG);
		wp_safe_redirect(add_query_arg(['did' => $fail && !$n ? 'failed' : (isset($did[$do]) ? ($ids ? $did[$do] : 'none') : 'none'), 'n' => $fail && !$n ? $fail : $n], remove_query_arg(['did', 'n'], $back)));
		exit;
	}
}

Y4APK_Members_List::init();
