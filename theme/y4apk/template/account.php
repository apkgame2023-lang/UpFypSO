<?php
/**
 * Every /account/… page and /u/<handle>/ (Y4APK_Members): one template, a
 * screen for each. What the page is about is in Y4APK_Members::$ctx.
 */
$c = Y4APK_Members::$ctx;
$screen = $c['screen'];
$M = 'Y4APK_Members';
$notices = [
	'out'         => __('You are signed out.', 'y4apk'),
	'reset'       => __('Password changed. Sign in with the new one.', 'y4apk'),
	'closed'      => __('Your account is closed.', 'y4apk'),
	'verified'    => __('Email confirmed. You can sign in.', 'y4apk'),
	'submitted'   => __('Thanks! Your script is in review. We email you when it is live.', 'y4apk'),
	'resubmitted' => __('Saved. It goes back in review, and we email you when it is live.', 'y4apk'),
	'deleted'     => __('Script deleted.', 'y4apk'),
	'pw'          => __('Password changed.', 'y4apk'),
];
$ok = isset($notices[$c['ok']]) ? $notices[$c['ok']] : '';
$old = $c['old'];
$v = function ($k, $d = '') use ($old) {
	return isset($old[$k]) ? $old[$k] : $d;
};
$statuses = ['live' => __('Live', 'y4apk'), 'review' => __('In review', 'y4apk'), 'changes' => __('Needs changes', 'y4apk'), 'draft' => __('Draft', 'y4apk')];

/** Errors and the confirmation, above a form. */
$messages = function () use ($c, $ok) {
	if ($ok) {
		echo '<div class="acc-note acc-note-ok" role="status">' . esc_html($ok) . '</div>';
	}
	foreach ($c['errors'] as $e) {
		echo '<div class="acc-note acc-note-err" role="alert">' . esc_html($e) . '</div>';
	}
};
$field = function ($label, $name, $type = 'text', $extra = []) use ($v) {
	$id = 'f-' . $name;
	$val = in_array($type, ['password'], true) ? '' : $v($name);
	echo '<div class="acc-field"><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
	echo '<div class="acc-input' . ($type === 'password' ? ' has-eye' : '') . '"><input id="' . esc_attr($id) . '" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($val) . '"';
	foreach ($extra as $k => $x) {
		echo ' ' . esc_attr($k) . ($x === true ? '' : '="' . esc_attr($x) . '"');
	}
	echo '>';
	if ($type === 'password') {
		echo '<button type="button" class="acc-eye" data-eye aria-label="' . esc_attr__('Show password', 'y4apk') . '"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M12 5C6.5 5 2.7 9.3 1.5 12c1.2 2.7 5 7 10.5 7s9.3-4.3 10.5-7C21.3 9.3 17.5 5 12 5Zm0 11.5a4.5 4.5 0 1 1 0-9 4.5 4.5 0 0 1 0 9Zm0-2.5a2 2 0 1 0 0-4 2 2 0 0 0 0 4Z"/></svg></button>';
	}
	echo '</div></div>';
};
$pitch = function ($title, $text) {
	?>
	<aside class="acc-pitch">
		<span class="acc-pitch-tag"><?php esc_html_e('Roblox scripts', 'y4apk'); ?></span>
		<h2><?php echo esc_html($title); ?></h2>
		<p><?php echo esc_html($text); ?></p>
		<ul>
			<li><?php esc_html_e('Share a loadstring in a minute: pick the game, paste the code.', 'y4apk'); ?></li>
			<li><?php esc_html_e('Reach the people who look for scripts every day.', 'y4apk'); ?></li>
			<li><?php esc_html_e('See how many people viewed each script.', 'y4apk'); ?></li>
		</ul>
	</aside>
	<?php
};
$shell_open = function ($pitch_title = '', $pitch_text = '') use ($pitch) {
	echo '<div class="acc-auth">';
	if ($pitch_title !== '') {
		$pitch($pitch_title, $pitch_text);
	}
	echo '<div class="acc-card acc-card-auth">';
};
$shell_close = function () {
	echo '</div></div>';
};
$form_open = function ($do, $extra = '') {
	echo '<form class="acc-form" method="post" action="' . esc_url(Y4APK_Members::url(Y4APK_Members::$ctx['view'] === 'edit' ? 'edit/' . absint(get_query_var('y4_id')) : Y4APK_Members::$ctx['view'])) . '"' . $extra . '>';
	echo '<input type="hidden" name="y4_do" value="' . esc_attr($do) . '">' . Y4APK_Members::nonce($do);
};

get_header();
?>
<div class="section list-content acc">
	<div class="wrp">
<?php

/* ======================================================= sign in */
if ($screen === 'login') {
	$shell_open(__('Welcome back', 'y4apk'), __('Sign in to see how your scripts are doing and to share new ones.', 'y4apk'));
	?>
	<h1><?php esc_html_e('Sign in', 'y4apk'); ?></h1>
	<?php $messages(); ?>
	<?php $form_open('login'); ?>
		<?php $field(__('Username or email', 'y4apk'), 'login', 'text', ['autocomplete' => 'username', 'required' => true, 'maxlength' => '100']); ?>
		<?php $field(__('Password', 'y4apk'), 'password', 'password', ['autocomplete' => 'current-password', 'required' => true, 'maxlength' => '100']); ?>
		<div class="acc-row"><label class="acc-check"><input type="checkbox" name="remember" value="1" checked> <span><?php esc_html_e('Keep me signed in', 'y4apk'); ?></span></label>
			<a href="<?php echo esc_url($M::url('forgot')); ?>"><?php esc_html_e('Forgot password?', 'y4apk'); ?></a></div>
		<?php echo $M::trap_fields(); $M::turnstile_widget(); ?>
		<button class="acc-btn acc-btn-primary" type="submit"><?php esc_html_e('Sign in', 'y4apk'); ?></button>
	</form>
	<?php if (!empty($c['can_resend'])) : ?>
		<?php $form_open('resend'); ?>
			<input type="hidden" name="login" value="<?php echo esc_attr($v('login')); ?>">
			<?php echo $M::trap_fields(); $M::turnstile_widget(); ?>
			<button class="acc-btn acc-btn-ghost" type="submit"><?php esc_html_e('Send the confirmation email again', 'y4apk'); ?></button>
		</form>
	<?php endif; ?>
	<?php if ($M::open()) : ?><p class="acc-alt"><?php esc_html_e('New here?', 'y4apk'); ?> <a href="<?php echo esc_url($M::url('register')); ?>"><?php esc_html_e('Create an account', 'y4apk'); ?></a></p><?php endif; ?>
	<?php
	$shell_close();

/* ======================================================= register */
} elseif ($screen === 'register') {
	$shell_open(__('Share your scripts', 'y4apk'), __('Make a free account to share Roblox scripts on Y4APK.', 'y4apk'));
	?>
	<h1><?php esc_html_e('Create an account', 'y4apk'); ?></h1>
	<?php if (!$M::open()) : ?>
		<div class="acc-note acc-note-err"><?php esc_html_e('Registration is closed at the moment.', 'y4apk'); ?></div>
		<p class="acc-alt"><a href="<?php echo esc_url($M::url('login')); ?>"><?php esc_html_e('Sign in', 'y4apk'); ?></a></p>
	<?php else : ?>
		<?php $messages(); ?>
		<?php $form_open('register'); ?>
			<?php $field(__('Username', 'y4apk'), 'handle', 'text', ['autocomplete' => 'username', 'required' => true, 'minlength' => '3', 'maxlength' => '20', 'pattern' => '[A-Za-z0-9_]{3,20}', 'autocapitalize' => 'none']); ?>
			<p class="acc-hint"><?php esc_html_e('3–20 letters, numbers or underscores. It is shown on the scripts you share.', 'y4apk'); ?></p>
			<?php $field(__('Email', 'y4apk'), 'email', 'email', ['autocomplete' => 'email', 'required' => true, 'maxlength' => '100']); ?>
			<p class="acc-hint"><?php esc_html_e('To confirm your account and tell you when a script is live. It is never shown.', 'y4apk'); ?></p>
			<?php $field(__('Password', 'y4apk'), 'password', 'password', ['autocomplete' => 'new-password', 'required' => true, 'minlength' => '10', 'maxlength' => '100']); ?>
			<p class="acc-hint"><?php esc_html_e('At least 10 characters.', 'y4apk'); ?></p>
			<label class="acc-check acc-terms"><input type="checkbox" name="terms" value="1" required> <span><?php esc_html_e('I only share scripts I trust. No scripts that steal accounts or harm other players.', 'y4apk'); ?></span></label>
			<?php echo $M::trap_fields(); $M::turnstile_widget(); ?>
			<button class="acc-btn acc-btn-primary" type="submit"><?php esc_html_e('Create account', 'y4apk'); ?></button>
		</form>
		<p class="acc-alt"><?php esc_html_e('Already have an account?', 'y4apk'); ?> <a href="<?php echo esc_url($M::url('login')); ?>"><?php esc_html_e('Sign in', 'y4apk'); ?></a></p>
	<?php endif;
	$shell_close();

/* ======================================================= "check your email" */
} elseif ($screen === 'check') {
	$shell_open();
	?>
	<div class="acc-big-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm9 7.2L4.5 7v.1L12 12.4 19.5 7.1V7L12 12.2Z"/></svg></div>
	<h1><?php esc_html_e('Check your email', 'y4apk'); ?></h1>
	<p class="acc-lead"><?php echo esc_html(empty($c['email']) ? __('If that account needs confirming, we sent a link to its email.', 'y4apk') : sprintf(__('We sent a link to %s. Open it to confirm your account.', 'y4apk'), $c['email'])); ?></p>
	<p class="acc-hint"><?php esc_html_e('It can take a few minutes. Look in Spam too. The link works for 48 hours.', 'y4apk'); ?></p>
	<p class="acc-alt"><a href="<?php echo esc_url($M::url('login')); ?>"><?php esc_html_e('Back to sign in', 'y4apk'); ?></a></p>
	<?php
	$shell_close();

/* ======================================================= email link */
} elseif ($screen === 'verify') {
	$shell_open();
	if (!empty($c['good'])) {
		?>
		<div class="acc-big-icon acc-big-ok" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="m9.5 17.5-5-5 1.4-1.4 3.6 3.6 8.6-8.6 1.4 1.4-10 10Z"/></svg></div>
		<h1><?php esc_html_e('Email confirmed', 'y4apk'); ?></h1>
		<p class="acc-lead"><?php esc_html_e('Your account is ready. Sign in to share your first script.', 'y4apk'); ?></p>
		<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url('login', ['ok' => 'verified'])); ?>"><?php esc_html_e('Sign in', 'y4apk'); ?></a>
		<?php
	} else {
		?>
		<div class="acc-big-icon acc-big-bad" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="M12 2 1 21h22L12 2Zm1 14h-2v-2h2v2Zm0-4h-2V8h2v4Z"/></svg></div>
		<h1><?php esc_html_e('This link does not work', 'y4apk'); ?></h1>
		<p class="acc-lead"><?php esc_html_e('It may have expired or been used already. If you can sign in, you are all set; if not, sign in and we can send a new one.', 'y4apk'); ?></p>
		<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url('login')); ?>"><?php esc_html_e('Go to sign in', 'y4apk'); ?></a>
		<?php
	}
	$shell_close();

/* ======================================================= forgot / reset */
} elseif ($screen === 'forgot' || $screen === 'forgot_sent') {
	$shell_open();
	if ($screen === 'forgot_sent') {
		?>
		<div class="acc-big-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="M3 5h18a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H3a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1Zm9 7.2L4.5 7v.1L12 12.4 19.5 7.1V7L12 12.2Z"/></svg></div>
		<h1><?php esc_html_e('Check your email', 'y4apk'); ?></h1>
		<p class="acc-lead"><?php esc_html_e('If there is an account for that name or email, we sent a link to choose a new password.', 'y4apk'); ?></p>
		<p class="acc-alt"><a href="<?php echo esc_url($M::url('login')); ?>"><?php esc_html_e('Back to sign in', 'y4apk'); ?></a></p>
		<?php
	} else {
		?>
		<h1><?php esc_html_e('Forgot your password?', 'y4apk'); ?></h1>
		<p class="acc-lead"><?php esc_html_e('Tell us your username or email and we send you a link to choose a new one.', 'y4apk'); ?></p>
		<?php $messages(); ?>
		<?php $form_open('forgot'); ?>
			<?php $field(__('Username or email', 'y4apk'), 'login', 'text', ['required' => true, 'maxlength' => '100']); ?>
			<?php echo $M::trap_fields(); $M::turnstile_widget(); ?>
			<button class="acc-btn acc-btn-primary" type="submit"><?php esc_html_e('Send the link', 'y4apk'); ?></button>
		</form>
		<p class="acc-alt"><a href="<?php echo esc_url($M::url('login')); ?>"><?php esc_html_e('Back to sign in', 'y4apk'); ?></a></p>
		<?php
	}
	$shell_close();
} elseif ($screen === 'reset' || $screen === 'reset_bad') {
	$shell_open();
	if ($screen === 'reset_bad') {
		?>
		<div class="acc-big-icon acc-big-bad" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="M12 2 1 21h22L12 2Zm1 14h-2v-2h2v2Zm0-4h-2V8h2v4Z"/></svg></div>
		<h1><?php esc_html_e('This link does not work', 'y4apk'); ?></h1>
		<p class="acc-lead"><?php esc_html_e('It may have expired or been used. Ask for a new one.', 'y4apk'); ?></p>
		<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url('forgot')); ?>"><?php esc_html_e('Send a new link', 'y4apk'); ?></a>
		<?php
	} else {
		?>
		<h1><?php esc_html_e('Choose a new password', 'y4apk'); ?></h1>
		<?php $messages(); ?>
		<?php $form_open('reset'); ?>
			<input type="hidden" name="key" value="<?php echo esc_attr($c['reset']['key']); ?>">
			<input type="hidden" name="login" value="<?php echo esc_attr($c['reset']['login']); ?>">
			<?php $field(__('New password', 'y4apk'), 'password', 'password', ['autocomplete' => 'new-password', 'required' => true, 'minlength' => '10', 'maxlength' => '100']); ?>
			<?php $field(__('Type it again', 'y4apk'), 'password2', 'password', ['autocomplete' => 'new-password', 'required' => true, 'minlength' => '10', 'maxlength' => '100']); ?>
			<button class="acc-btn acc-btn-primary" type="submit"><?php esc_html_e('Save password', 'y4apk'); ?></button>
		</form>
		<?php
	}
	$shell_close();

/* ======================================================= not for these visitors */
} elseif ($screen === 'staff') {
	$shell_open();
	?>
	<h1><?php esc_html_e('This page is for members', 'y4apk'); ?></h1>
	<p class="acc-lead"><?php esc_html_e('You are signed in as part of the team. Members share scripts here; you review them in the site’s dashboard.', 'y4apk'); ?></p>
	<a class="acc-btn acc-btn-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . (defined('Y4APK_MENU') ? Y4APK_MENU : 'y4apk-dashboard'))); ?>"><?php esc_html_e('Open the dashboard', 'y4apk'); ?></a>
	<?php
	$shell_close();
} elseif ($screen === 'notfound') {
	$shell_open();
	?>
	<h1><?php esc_html_e('We could not find that script', 'y4apk'); ?></h1>
	<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url()); ?>"><?php esc_html_e('My scripts', 'y4apk'); ?></a>
	<?php
	$shell_close();
} elseif ($screen === 'unverified' || $screen === 'closed') {
	$shell_open();
	?>
	<h1><?php echo esc_html($screen === 'closed' ? __('Sharing is closed at the moment', 'y4apk') : __('Confirm your email first', 'y4apk')); ?></h1>
	<p class="acc-lead"><?php echo esc_html($screen === 'closed' ? __('New scripts are not being accepted right now. Your scripts are safe. Come back soon.', 'y4apk') : __('Open the link we sent to your email, then come back here to share a script.', 'y4apk')); ?></p>
	<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url()); ?>"><?php esc_html_e('My scripts', 'y4apk'); ?></a>
	<?php
	$shell_close();

/* ======================================================= dashboard */
} elseif ($screen === 'home') {
	$u = $c['user'];
	$st = $c['stats'];
	?>
	<section class="acc-hero">
		<div class="acc-hero-who">
			<?php echo $M::avatar($u->user_login, 64); ?>
			<div>
				<h1>@<?php echo esc_html($u->user_login); ?></h1>
				<p><?php echo esc_html(sprintf(__('Member since %s', 'y4apk'), mysql2date(get_option('date_format'), $u->user_registered))); ?></p>
			</div>
		</div>
		<div class="acc-hero-actions">
			<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url('submit')); ?>"><svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor"><path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/></svg><?php esc_html_e('Share a script', 'y4apk'); ?></a>
			<a class="acc-btn acc-btn-ghost" href="<?php echo esc_url($M::profile_url($u)); ?>" target="_blank" rel="noopener"><?php esc_html_e('My public page', 'y4apk'); ?></a>
		</div>
		<ul class="acc-stats">
			<li><b><?php echo esc_html(number_format_i18n($st['all'])); ?></b> <?php esc_html_e('Scripts', 'y4apk'); ?></li>
			<li><b><?php echo esc_html(number_format_i18n($st['live'])); ?></b> <?php esc_html_e('Live', 'y4apk'); ?></li>
			<li><b><?php echo esc_html(number_format_i18n($st['review'])); ?></b> <?php esc_html_e('In review', 'y4apk'); ?></li>
			<li><b><?php echo esc_html(Y4APK_Roblox::short_number($st['views'])); ?></b> <?php esc_html_e('Views', 'y4apk'); ?></li>
		</ul>
	</section>
	<nav class="acc-tabs"><a class="is-on" href="<?php echo esc_url($M::url()); ?>"><?php esc_html_e('My scripts', 'y4apk'); ?></a><a href="<?php echo esc_url($M::url('settings')); ?>"><?php esc_html_e('Settings', 'y4apk'); ?></a><a href="<?php echo esc_url($M::logout_url()); ?>"><?php esc_html_e('Sign out', 'y4apk'); ?></a></nav>
	<?php $messages(); ?>
	<?php if (!$c['scripts']) : ?>
		<div class="acc-card acc-empty">
			<div class="acc-big-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="34" height="34" fill="currentColor"><path d="M8.6 16.6 4 12l4.6-4.6L7.2 6 1.2 12l6 6 1.4-1.4Zm6.8 0L20 12l-4.6-4.6L16.8 6l6 6-6 6-1.4-1.4Z"/></svg></div>
			<h2><?php esc_html_e('You have not shared a script yet', 'y4apk'); ?></h2>
			<p><?php esc_html_e('Pick a game, paste the code, and send it. We check it and it goes live.', 'y4apk'); ?></p>
			<a class="acc-btn acc-btn-primary" href="<?php echo esc_url($M::url('submit')); ?>"><?php esc_html_e('Share your first script', 'y4apk'); ?></a>
		</div>
	<?php else : ?>
		<div class="acc-list">
			<?php foreach ($c['scripts'] as $s) : $p = $s['post']; ?>
			<article class="acc-item">
				<div class="acc-item-main">
					<span class="acc-badge acc-badge-<?php echo esc_attr($s['key']); ?>"><?php echo esc_html($statuses[$s['key']]); ?></span>
					<h3><?php echo $s['key'] === 'live' ? '<a href="' . esc_url(get_permalink($p)) . '">' . esc_html(wp_strip_all_tags(get_the_title($p))) . '</a>' : esc_html(wp_strip_all_tags(get_the_title($p))); ?></h3>
					<p class="acc-item-meta">
						<?php if ($s['game'] !== '') : ?><span><?php echo esc_html($s['game']); ?></span><?php endif; ?>
						<span><?php echo esc_html(sprintf(__('Updated %s', 'y4apk'), Y4APK_Roblox::ago(get_post_datetime($p, 'modified')->getTimestamp()))); ?></span>
						<?php if ($s['key'] === 'live') : ?><span><?php echo esc_html(sprintf(_n('%s view', '%s views', $s['views'], 'y4apk'), Y4APK_Roblox::short_number($s['views']))); ?></span><?php endif; ?>
					</p>
					<?php if ($s['key'] === 'changes' && $s['reason'] !== '') : ?><p class="acc-reason"><b><?php esc_html_e('What to change:', 'y4apk'); ?></b> <?php echo esc_html($s['reason']); ?></p><?php endif; ?>
				</div>
				<div class="acc-item-actions">
					<a class="acc-btn acc-btn-small" href="<?php echo esc_url($M::url('edit/' . $p->ID)); ?>"><?php esc_html_e('Edit', 'y4apk'); ?></a>
					<form method="post" action="<?php echo esc_url($M::url()); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this script?', 'y4apk')); ?>')">
						<input type="hidden" name="y4_do" value="delete"><input type="hidden" name="id" value="<?php echo esc_attr($p->ID); ?>"><?php echo $M::nonce('delete'); ?>
						<button class="acc-btn acc-btn-small acc-btn-danger" type="submit"><?php esc_html_e('Delete', 'y4apk'); ?></button>
					</form>
				</div>
			</article>
			<?php endforeach; ?>
		</div>
		<?php if ($st['live'] && $st['review']) : ?><p class="acc-hint"><?php esc_html_e('A script you change goes back in review; until it is checked again it is not shown.', 'y4apk'); ?></p><?php endif; ?>
	<?php endif;

/* ======================================================= settings */
} elseif ($screen === 'settings') {
	$u = $c['user'];
	?>
	<section class="acc-hero acc-hero-slim">
		<div class="acc-hero-who"><?php echo $M::avatar($u->user_login, 52); ?><div><h1><?php esc_html_e('Account settings', 'y4apk'); ?></h1><p>@<?php echo esc_html($u->user_login); ?></p></div></div>
	</section>
	<nav class="acc-tabs"><a href="<?php echo esc_url($M::url()); ?>"><?php esc_html_e('My scripts', 'y4apk'); ?></a><a class="is-on" href="<?php echo esc_url($M::url('settings')); ?>"><?php esc_html_e('Settings', 'y4apk'); ?></a><a href="<?php echo esc_url($M::logout_url()); ?>"><?php esc_html_e('Sign out', 'y4apk'); ?></a></nav>
	<?php $messages(); ?>
	<div class="acc-two">
		<div class="acc-card">
			<h2><?php esc_html_e('Change password', 'y4apk'); ?></h2>
			<?php $form_open('password'); ?>
				<?php $field(__('Current password', 'y4apk'), 'current', 'password', ['autocomplete' => 'current-password', 'required' => true]); ?>
				<?php $field(__('New password', 'y4apk'), 'password', 'password', ['autocomplete' => 'new-password', 'required' => true, 'minlength' => '10', 'maxlength' => '100']); ?>
				<?php $field(__('Type it again', 'y4apk'), 'password2', 'password', ['autocomplete' => 'new-password', 'required' => true, 'minlength' => '10', 'maxlength' => '100']); ?>
				<button class="acc-btn acc-btn-primary" type="submit"><?php esc_html_e('Save password', 'y4apk'); ?></button>
			</form>
		</div>
		<div class="acc-card">
			<h2><?php esc_html_e('Your details', 'y4apk'); ?></h2>
			<dl class="acc-dl"><dt><?php esc_html_e('Username', 'y4apk'); ?></dt><dd>@<?php echo esc_html($u->user_login); ?></dd><dt><?php esc_html_e('Email', 'y4apk'); ?></dt><dd><?php echo esc_html($u->user_email); ?></dd></dl>
			<p class="acc-hint"><?php esc_html_e('We use your email only to confirm your account and to tell you when a script is live.', 'y4apk'); ?></p>
			<details class="acc-danger">
				<summary><?php esc_html_e('Close my account', 'y4apk'); ?></summary>
				<p><?php esc_html_e('This removes your account and email. It cannot be undone.', 'y4apk'); ?></p>
				<?php $form_open('close'); ?>
					<?php $field(__('Type your username', 'y4apk'), 'confirm', 'text', ['required' => true, 'autocomplete' => 'off']); ?>
					<?php $field(__('Your password', 'y4apk'), 'current', 'password', ['required' => true, 'autocomplete' => 'current-password']); ?>
					<label class="acc-check"><input type="checkbox" name="delete_scripts" value="1"> <span><?php esc_html_e('Also delete the scripts I shared (otherwise they stay on the site, without your name)', 'y4apk'); ?></span></label>
					<button class="acc-btn acc-btn-danger" type="submit"><?php esc_html_e('Close my account', 'y4apk'); ?></button>
				</form>
			</details>
		</div>
	</div>
	<?php

/* ======================================================= share / edit a script */
} elseif ($screen === 'submit' || $screen === 'edit') {
	$editing = $screen === 'edit' && $c['post'];
	$codes = $v('codes', [['label' => '', 'code' => '']]);
	$codes = $codes ?: [['label' => '', 'code' => '']];
	?>
	<div class="acc-crumb"><a href="<?php echo esc_url($M::url()); ?>">← <?php esc_html_e('My scripts', 'y4apk'); ?></a></div>
	<div class="acc-submit">
		<div class="acc-card">
			<h1><?php echo esc_html($editing ? __('Edit your script', 'y4apk') : __('Share a script', 'y4apk')); ?></h1>
			<?php if ($editing && $c['post']->post_status === 'publish') : ?><div class="acc-note acc-note-warn"><?php esc_html_e('When you save, this script goes back in review and is hidden until it is checked again.', 'y4apk'); ?></div><?php endif; ?>
			<?php if ($editing && (string) get_post_meta($c['post']->ID, Y4APK_Members::REJECT, true) !== '') : ?><div class="acc-note acc-note-err"><b><?php esc_html_e('What to change:', 'y4apk'); ?></b> <?php echo esc_html(get_post_meta($c['post']->ID, Y4APK_Members::REJECT, true)); ?></div><?php endif; ?>
			<?php $messages(); ?>
			<?php $form_open($editing ? 'edit' : 'submit'); ?>
				<div class="acc-step"><span>1</span><?php esc_html_e('Which game is it for?', 'y4apk'); ?></div>
				<div class="acc-field">
					<label for="f-game"><?php esc_html_e('Game', 'y4apk'); ?></label>
					<div class="acc-input"><select id="f-game" name="game">
						<option value="0"><?php esc_html_e('Choose a game…', 'y4apk'); ?></option>
						<?php foreach ($c['games'] as $g) : ?><option value="<?php echo esc_attr($g->term_id); ?>" <?php selected((int) $v('game'), (int) $g->term_id); ?>><?php echo esc_html($g->name); ?></option><?php endforeach; ?>
					</select></div>
				</div>
				<?php $field(__('Not in the list? Type the game’s name', 'y4apk'), 'game_new', 'text', ['maxlength' => '60', 'placeholder' => 'Blox Fruits']); ?>

				<div class="acc-step"><span>2</span><?php esc_html_e('Name it', 'y4apk'); ?></div>
				<?php $field(__('Script name', 'y4apk'), 'hub', 'text', ['required' => true, 'minlength' => '2', 'maxlength' => '40', 'placeholder' => 'Zee Hub']); ?>
				<label class="acc-switch"><input type="checkbox" name="keyless" value="1" <?php checked((int) $v('keyless', 1), 1); ?>><i></i><span><?php esc_html_e('No key needed', 'y4apk'); ?></span></label>

				<div class="acc-step"><span>3</span><?php esc_html_e('Paste the code', 'y4apk'); ?></div>
				<div data-codes>
				<?php foreach ($codes as $i => $row) : ?>
					<div class="acc-code" data-code>
						<input class="acc-code-label" type="text" name="label[]" value="<?php echo esc_attr($row['label']); ?>" maxlength="30" placeholder="<?php esc_attr_e('Name, like Mobile (optional)', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Code name', 'y4apk'); ?>">
						<textarea name="code[]" rows="3" maxlength="4000" spellcheck="false" placeholder='loadstring(game:HttpGet("https://…"))()' aria-label="<?php esc_attr_e('Code', 'y4apk'); ?>"><?php echo esc_textarea($row['code']); ?></textarea>
					</div>
				<?php endforeach; ?>
				</div>
				<button type="button" class="acc-add" data-add-code <?php echo count($codes) >= 3 ? 'hidden' : ''; ?>>+ <?php esc_html_e('Add another code (Thai, mobile…)', 'y4apk'); ?></button>
				<template id="acc-code-tpl"><div class="acc-code" data-code><input class="acc-code-label" type="text" name="label[]" maxlength="30" placeholder="<?php esc_attr_e('Name, like Mobile (optional)', 'y4apk'); ?>"><textarea name="code[]" rows="3" maxlength="4000" spellcheck="false" placeholder='loadstring(game:HttpGet("https://…"))()'></textarea></div></template>

				<div class="acc-step"><span>4</span><?php esc_html_e('Say what it does (optional)', 'y4apk'); ?></div>
				<div class="acc-field"><div class="acc-input"><textarea name="desc" rows="3" maxlength="600" placeholder="<?php esc_attr_e('Auto farm, teleport, fruit sniper…', 'y4apk'); ?>"><?php echo esc_textarea($v('desc')); ?></textarea></div></div>

				<?php echo $M::trap_fields(); $M::turnstile_widget(); ?>
				<button class="acc-btn acc-btn-primary acc-btn-wide" type="submit"><?php echo esc_html($editing ? __('Save and send for review', 'y4apk') : __('Send for review', 'y4apk')); ?></button>
			</form>
		</div>
		<aside class="acc-card acc-side">
			<h2><?php esc_html_e('How it works', 'y4apk'); ?></h2>
			<ol class="acc-steps"><li><b><?php esc_html_e('You send it', 'y4apk'); ?></b><span><?php esc_html_e('It stays private until it is checked.', 'y4apk'); ?></span></li><li><b><?php esc_html_e('We check it', 'y4apk'); ?></b><span><?php esc_html_e('Usually within a day.', 'y4apk'); ?></span></li><li><b><?php esc_html_e('It goes live', 'y4apk'); ?></b><span><?php esc_html_e('We email you, and it shows under your name.', 'y4apk'); ?></span></li></ol>
			<h2><?php esc_html_e('Good to know', 'y4apk'); ?></h2>
			<ul class="acc-rules"><li><?php esc_html_e('Share the loadstring line, not a whole script.', 'y4apk'); ?></li><li><?php esc_html_e('Keep the link working: scripts that stop working get removed.', 'y4apk'); ?></li><li><?php esc_html_e('Scripts that steal accounts are removed and the account is closed.', 'y4apk'); ?></li></ul>
		</aside>
	</div>
	<?php

/* ======================================================= public page */
} elseif ($screen === 'profile' && !empty($c['profile'])) {
	$u = $c['profile'];
	$q = $c['query'];
	?>
	<section class="acc-hero">
		<div class="acc-hero-who">
			<?php echo $M::avatar($u->user_login, 72); ?>
			<div><h1>@<?php echo esc_html($u->user_login); ?></h1><p><?php echo esc_html(sprintf(__('Member since %s', 'y4apk'), mysql2date(get_option('date_format'), $u->user_registered))); ?></p></div>
		</div>
		<ul class="acc-stats">
			<li><b><?php echo esc_html(number_format_i18n($q->found_posts)); ?></b> <?php echo esc_html(_n('Script', 'Scripts', $q->found_posts, 'y4apk')); ?></li>
			<li><b><?php echo esc_html(Y4APK_Roblox::short_number($c['views'])); ?></b> <?php esc_html_e('Views', 'y4apk'); ?></li>
		</ul>
	</section>
	<?php if ($q->have_posts()) : ?>
		<div class="rbx-grid">
			<?php foreach ($q->posts as $p) {
				Y4APK_Roblox::card($p->ID, true);
			} ?>
		</div>
		<?php y4apk_pagination(max(1, (int) get_query_var('paged')), (int) $q->max_num_pages); ?>
	<?php else : ?>
		<div class="acc-card acc-empty"><p><?php esc_html_e('No scripts here yet.', 'y4apk'); ?></p></div>
	<?php endif;
}
?>
	</div>
</div>
<script>
(function () {
	document.addEventListener('click', function (e) {
		var eye = e.target.closest && e.target.closest('[data-eye]');
		if (eye) {
			var i = eye.parentNode.querySelector('input');
			i.type = i.type === 'password' ? 'text' : 'password';
			eye.classList.toggle('is-on', i.type === 'text');
			return;
		}
		var add = e.target.closest && e.target.closest('[data-add-code]');
		if (add) {
			var box = document.querySelector('[data-codes]'), tpl = document.getElementById('acc-code-tpl');
			if (box.querySelectorAll('[data-code]').length < 3) {
				box.appendChild(tpl.content.cloneNode(true));
				box.lastElementChild.querySelector('textarea').focus();
			}
			if (box.querySelectorAll('[data-code]').length >= 3) { add.hidden = true; }
		}
	});
})();
</script>
<?php // Named y4apk-pwa-…: the speed plugin's delay already leaves y4apk-pwa scripts alone. ?>
<script id="y4apk-pwa-account" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
<?php if (Y4APK_Members::turnstile_on() && in_array($screen, ['login', 'register', 'forgot', 'submit', 'edit'], true)) : ?>
	// Cloudflare Turnstile is added from here: as a plain <script src> a speed plugin holds it back until the visitor moves.
	var ts = document.createElement('script');
	ts.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
	ts.async = true;
	document.head.appendChild(ts);
<?php endif; ?>
	// A page cache may have kept this page: ask for fresh hidden values, and hold a quick submit until they are in.
	var forms = [].slice.call(document.querySelectorAll('form')).filter(function (f) { return f.querySelector('[name="_y4n"]'); });
	if (!forms.length || !window.fetch || !window.FormData) { return; }
	var body = new FormData(), done = false;
	body.append('action', 'y4apk_fresh');
	forms.forEach(function (f) { var d = f.querySelector('[name="y4_do"]'); if (d) { body.append('do[]', d.value); } });
	var ready = fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, {method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store'})
		.then(function (r) { return r.json(); })
		.then(function (j) {
			forms.forEach(function (f) {
				var t = f.querySelector('[name="y4t"]'), n = f.querySelector('[name="_y4n"]'), d = f.querySelector('[name="y4_do"]');
				if (t && j.t && !(j.now - parseInt(t.value, 10) < 3600)) { t.value = j.t; }
				if (n && d && j.n && j.n[d.value]) { n.value = j.n[d.value]; }
			});
		})
		.catch(function () {})
		.then(function () { done = true; });
	forms.forEach(function (f) {
		f.addEventListener('submit', function (e) {
			if (done) { return; }
			e.preventDefault();
			var go = function () { if (!f.y4sent) { f.y4sent = true; HTMLFormElement.prototype.submit.call(f); } };
			ready.then(go);
			setTimeout(go, 4000);
		});
	});
})();
</script>
<?php
get_template_part('template/footer');
get_footer();
