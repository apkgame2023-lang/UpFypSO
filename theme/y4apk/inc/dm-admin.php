<?php
/**
 * Y4APK ▸ Messages (2.67). Included by Y4APK_DM::render(). The owner reads
 * only reported messages (and the few before them, for what they answer):
 * everything else stays private between the members.
 */
defined('ABSPATH') || exit;

global $wpdb;
$D = 'Y4APK_DM';
$s = $D::settings();
$tabs = ['reports' => __('Reports', 'y4apk'), 'done' => __('Handled', 'y4apk'), 'settings' => __('Settings', 'y4apk')];
$tab = isset($_GET['tab']) && isset($tabs[$_GET['tab']]) ? sanitize_key($_GET['tab']) : 'reports'; // phpcs:ignore WordPress.Security.NonceVerification
$note = isset($_GET['note']) ? sanitize_key($_GET['note']) : ''; // phpcs:ignore WordPress.Security.NonceVerification
$notes = ['dismissed' => __('Dismissed: the message stays.', 'y4apk'), 'removed' => __('The message is removed for both members.', 'y4apk'),
	'banned' => __('The member is banned and signed out everywhere.', 'y4apk'), 'saved' => __('Saved.', 'y4apk')];
$ready = get_option('y4apk_dm_db') === $D::DBV;
$tt = $D::t('threads');
$tm = $D::t('msgs');
$tr = $D::t('reports');
$day = time() - DAY_IN_SECONDS;
$k = $ready ? [
	'threads' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $tt WHERE last_id > 0"),
	'msgs24'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tm WHERE made > %d", $day)),
	'people'  => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT from_id) FROM $tm WHERE made > %d", time() - 7 * DAY_IN_SECONDS)),
	'open'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM $tr WHERE status = 'open'"),
] : ['threads' => 0, 'msgs24' => 0, 'people' => 0, 'open' => 0];
$form = function ($do, $id = 0) use ($tab) {
	echo '<input type="hidden" name="action" value="y4apk_dm"><input type="hidden" name="do" value="' . esc_attr($do) . '"><input type="hidden" name="tab" value="' . esc_attr($tab) . '">';
	if ($id) {
		echo '<input type="hidden" name="id" value="' . (int) $id . '">';
	}
	wp_nonce_field('y4apk_dm_admin');
};
$member = function ($id) {
	$u = $id ? get_userdata((int) $id) : null;
	return $u ? '<a href="' . esc_url(Y4APK_Members::profile_url($u)) . '" target="_blank"><b>@' . esc_html($u->user_login) . '</b></a>' : '<span class="y4ui-kpi-s">' . esc_html__('(closed account)', 'y4apk') . '</span>';
};
$whys = ['spam' => __('Spam', 'y4apk'), 'scam' => __('Scam or phishing', 'y4apk'), 'abuse' => __('Abuse or harassment', 'y4apk'), 'other' => __('Something else', 'y4apk')];
?>
<div class="wrap y4ui y4dm">
	<?php Y4APK_Admin_UI::head([
		'icon'  => 'chat',
		'tint'  => 'violet',
		'title' => esc_html__('Messages', 'y4apk'),
		'sub'   => esc_html__('Members write to each other from their profiles (Message) and at /account/messages/. Messages are kept encrypted and are private: you only see the ones a member reports, with the few before them.', 'y4apk'),
	]); ?>
	<?php if ($note && isset($notes[$note])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notes[$note]); ?></p></div><?php endif; ?>

	<div class="y4ui-kpis">
		<a class="y4ui-kpi<?php echo $k['open'] ? ' is-red' : ''; ?>" href="<?php echo esc_url($D::admin_url()); ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Reports waiting', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($k['open'])); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('messages members reported', 'y4apk'); ?></span></a>
		<div class="y4ui-kpi"><span class="y4ui-kpi-l"><?php esc_html_e('Messages, 24 hours', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($k['msgs24'])); ?></span><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('%s members wrote this week', 'y4apk'), number_format_i18n($k['people']))); ?></span></div>
		<div class="y4ui-kpi"><span class="y4ui-kpi-l"><?php esc_html_e('Conversations', 'y4apk'); ?></span><span class="y4ui-kpi-n"><?php echo esc_html(number_format_i18n($k['threads'])); ?></span><span class="y4ui-kpi-s"><?php esc_html_e('in all', 'y4apk'); ?></span></div>
		<div class="y4ui-kpi<?php echo $s['on'] ? ' is-green' : ''; ?>"><span class="y4ui-kpi-l"><?php esc_html_e('Messages', 'y4apk'); ?></span><span class="y4ui-kpi-n" style="font-size:20px"><?php echo esc_html($s['on'] ? __('On', 'y4apk') : __('Off', 'y4apk')); ?></span><span class="y4ui-kpi-s"><?php echo esc_html(sprintf(__('%1$d a minute · %2$d new conversations a day', 'y4apk'), $s['per_min'], $s['new_day'])); ?></span></div>
	</div>

	<div class="y4ui-bar-row"><nav class="y4ui-tabs">
		<?php foreach ($tabs as $key => $label) : ?>
			<a href="<?php echo esc_url($D::admin_url(['tab' => $key])); ?>" class="<?php echo $tab === $key ? 'is-on' : ''; ?>"><?php echo esc_html($label); ?><?php if ($key === 'reports' && $k['open']) : ?> <span class="y4ui-chip is-red"><?php echo esc_html(number_format_i18n($k['open'])); ?></span><?php endif; ?></a>
		<?php endforeach; ?>
	</nav></div>

	<?php if ($tab === 'settings') : ?>
		<div class="y4ui-card">
			<form class="y4ui-card-b" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<?php $form('settings'); ?>
				<label class="y4ui-opt"><input type="checkbox" name="on" value="1" <?php checked($s['on']); ?>> <span><b><?php esc_html_e('Members can send messages', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php esc_html_e('Off: the Message buttons go and nobody can send; conversations stay readable.', 'y4apk'); ?></span></span></label>
				<label class="y4ui-opt"><input type="checkbox" name="mail" value="1" <?php checked($s['mail']); ?>> <span><b><?php esc_html_e('Email members about new messages', 'y4apk'); ?></b><br><span class="y4ui-hint"><?php esc_html_e('“@name sent you a message”, never what it says; at most one every 6 hours, and not while they are on the site. Each member can turn it off.', 'y4apk'); ?></span></span></label>
				<div class="y4ui-fields" style="margin-top:12px">
					<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('Messages a member may send in a minute', 'y4apk'); ?></span><input type="number" min="3" max="120" name="per_min" value="<?php echo esc_attr($s['per_min']); ?>"></label>
					<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('…and in a day', 'y4apk'); ?></span><input type="number" min="20" max="5000" name="per_day" value="<?php echo esc_attr($s['per_day']); ?>"></label>
					<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('New conversations a member may start in a day', 'y4apk'); ?></span><input type="number" min="1" max="200" name="new_day" value="<?php echo esc_attr($s['new_day']); ?>"></label>
					<label class="y4ui-field"><span class="y4ui-label"><?php esc_html_e('…for an account less than a day old (spam)', 'y4apk'); ?></span><input type="number" min="0" max="50" name="young_new" value="<?php echo esc_attr($s['young_new']); ?>"></label>
				</div>
				<div class="y4ui-savebar"><span class="y4ui-hint"><?php esc_html_e('Members with an unconfirmed email cannot send. A block stops both ways.', 'y4apk'); ?></span><button class="button button-primary button-large"><?php esc_html_e('Save', 'y4apk'); ?></button></div>
			</form>
		</div>
	<?php else :
		$rows = $ready ? (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $tr WHERE status = %s ORDER BY id DESC LIMIT 50", $tab === 'done' ? 'done' : 'open')) : [];
		?>
		<?php if (!$rows) : ?>
			<div class="y4ui-card"><div class="y4ui-empty"><?php echo esc_html($tab === 'done' ? __('Nothing handled yet.', 'y4apk') : __('No reports. When a member reports a message, it shows here.', 'y4apk')); ?></div></div>
		<?php endif; ?>
		<?php foreach ($rows as $r) :
			$before = (array) $wpdb->get_results($wpdb->prepare("SELECT * FROM $tm WHERE thread_id = %d AND id < %d ORDER BY id DESC LIMIT 3", (int) $r->thread_id, (int) $r->msg_id));
			$text = Y4APK_Mail::reveal((string) $r->copy);
			?>
			<div class="y4ui-card y4dm-rep">
				<div class="y4ui-card-h"><div><h2><?php echo $member($r->about_id); // phpcs:ignore WordPress.Security.EscapeOutput ?> <span class="y4ui-chip is-amber"><?php echo esc_html($whys[$r->why] ?? $r->why); ?></span></h2>
					<p><?php echo wp_kses(sprintf(__('Reported by %1$s · %2$s', 'y4apk'), $member($r->by_id), esc_html(wp_date(get_option('date_format') . ' H:i', (int) $r->made))), ['a' => ['href' => [], 'target' => []], 'b' => [], 'span' => ['class' => []]]); ?></p></div></div>
				<div class="y4ui-card-b">
					<?php if ($before) : ?><div class="y4dm-ctx"><?php foreach (array_reverse($before) as $b) : ?>
						<div class="y4dm-line<?php echo (int) $b->from_id === (int) $r->about_id ? ' is-them' : ''; ?>"><small>@<?php $bu = get_userdata((int) $b->from_id); echo esc_html($bu ? $bu->user_login : '—'); ?></small><span><?php echo (int) $b->gone ? '<i>' . esc_html__('unsent', 'y4apk') . '</i>' : $D::html(Y4APK_Mail::reveal((string) $b->body)); // phpcs:ignore WordPress.Security.EscapeOutput ?></span></div>
					<?php endforeach; ?></div><?php endif; ?>
					<div class="y4dm-line is-them is-reported"><small><?php esc_html_e('Reported message', 'y4apk'); ?></small><span><!--email_off--><?php echo $D::html($text); // phpcs:ignore WordPress.Security.EscapeOutput ?><!--/email_off--></span></div>
					<?php if ($tab !== 'done') : ?>
					<div class="y4dm-acts">
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('remove', $r->id); ?><button class="button"><?php esc_html_e('Remove the message', 'y4apk'); ?></button></form>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Ban this member? They are signed out and cannot sign in again.', 'y4apk')); ?>')"><?php $form('ban', $r->id); ?><button class="button button-link-delete"><?php esc_html_e('Ban the member', 'y4apk'); ?></button></form>
						<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><?php $form('dismiss', $r->id); ?><button class="button button-link"><?php esc_html_e('Dismiss', 'y4apk'); ?></button></form>
					</div>
					<?php endif; ?>
				</div>
			</div>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
<style>
.y4dm-rep .y4ui-card-h h2{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.y4dm-ctx{display:grid;gap:6px;margin-bottom:8px;opacity:.75}
.y4dm-line{display:grid;gap:2px;max-width:640px;padding:8px 12px;border-radius:12px;background:#f0f0f1}
.y4dm-line.is-them{background:#eef4ff}
.y4dm-line.is-reported{background:#fff4e5;box-shadow:inset 0 0 0 1px #f0b849}
.y4dm-line small{color:#646970;font-weight:600}
.y4dm-line span{white-space:normal;overflow-wrap:anywhere}
.y4dm-acts{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:12px}
.y4dm-acts form{margin:0}
</style>
