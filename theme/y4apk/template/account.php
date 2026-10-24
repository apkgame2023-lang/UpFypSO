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
	$args = isset($_GET['next']) && $_GET['next'] === 'upload' ? ['next' => 'upload'] : []; // sign in, then back to /upload/
	echo '<form class="acc-form" method="post" action="' . esc_url(Y4APK_Members::url(Y4APK_Members::$ctx['view'] === 'edit' ? 'edit/' . absint(get_query_var('y4_id')) : Y4APK_Members::$ctx['view'], $args)) . '"' . $extra . '>';
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

/* ======================================================= share / edit a script: /upload/, four steps */
} elseif ($screen === 'submit' || $screen === 'edit') {
	$editing = $screen === 'edit' && $c['post'];
	$codes = $v('codes', [['label' => '', 'code' => '']]);
	$codes = $codes ?: [['label' => '', 'code' => '']];
	$games = [];
	foreach ($c['games'] as $g) {
		if (strtolower($g->name) === 'universal') {
			continue; // the "Any game" card
		}
		$games[] = ['id' => (int) $g->term_id, 'name' => $g->name, 'icon' => Y4APK_Roblox::icon_url($g), 'art' => Y4APK_Roblox::artwork($g), 'count' => (int) $g->count,
			'place' => (string) get_term_meta($g->term_id, Y4APK_Roblox::M_PLACE, true)];
	}
	$popular = $games;
	usort($popular, function ($a, $b) { return $b['count'] - $a['count']; });
	$popular = array_slice($popular, 0, 8);
	$start = (int) $v('step', 0);
	$start = $start ?: ($c['errors'] ? 4 : ($editing ? 2 : 1));
	$scope = $v('scope', 'game');
	$keysys = !(int) $v('keyless', 1);
	$me = $c['user'];
	$icon = function ($name) {
		$p = [
			'code'  => '<path d="M8.6 16.6 4 12l4.6-4.6L7.2 6 1.2 12l6 6 1.4-1.4Zm6.8 0L20 12l-4.6-4.6L16.8 6l6 6-6 6-1.4-1.4Z"/>',
			'pad'   => '<path d="M7 6h10a5 5 0 0 1 4.9 6l-1 5a3 3 0 0 1-5.2 1.4L13.6 16h-3.2l-2.1 2.4A3 3 0 0 1 3.1 17l-1-5A5 5 0 0 1 7 6Zm1 3v2H6v2h2v2h2v-2h2v-2h-2V9H8Zm8.5 1a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>',
			'globe' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.9 6h-3a15.7 15.7 0 0 0-1.4-3.6A8 8 0 0 1 18.9 8ZM12 4c.8 1.2 1.5 2.5 1.9 4h-3.8c.4-1.5 1.1-2.8 1.9-4ZM4.3 14a8.2 8.2 0 0 1 0-4h3.4a16.5 16.5 0 0 0 0 4H4.3Zm.8 2h3a15.7 15.7 0 0 0 1.4 3.6A8 8 0 0 1 5.1 16Zm3-8h-3a8 8 0 0 1 4.4-3.6C8.9 5.5 8.4 6.7 8.1 8ZM12 20c-.8-1.2-1.5-2.5-1.9-4h3.8c-.4 1.5-1.1 2.8-1.9 4Zm2.3-6H9.7a14.7 14.7 0 0 1 0-4h4.6a14.7 14.7 0 0 1 0 4Zm.2 5.6c.6-1.1 1.1-2.3 1.4-3.6h3a8 8 0 0 1-4.4 3.6ZM16.3 14a16.5 16.5 0 0 0 0-4h3.4a8.2 8.2 0 0 1 0 4h-3.4Z"/>',
			'search' => '<path d="M10.5 3a7.5 7.5 0 0 1 6 12l4.7 4.8-1.4 1.4-4.8-4.7A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/>',
			'key'   => '<path d="M7 14a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm5.6-4A6 6 0 1 0 12.6 14H17v4h4v-4h2v-4H12.6Z"/>',
			'check' => '<path d="m9.5 16.2-4.2-4.2-1.4 1.4 5.6 5.6 11-11-1.4-1.4-9.6 9.6Z"/>',
			'arrow' => '<path d="M13.2 5.3 19.9 12l-6.7 6.7-1.4-1.4 4.3-4.3H4v-2h12.1l-4.3-4.3 1.4-1.4Z"/>',
			'back'  => '<path d="M10.8 5.3 4.1 12l6.7 6.7 1.4-1.4L7.9 13H20v-2H7.9l4.3-4.3-1.4-1.4Z"/>',
		];
		return '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">' . $p[$name] . '</svg>';
	};
	?>
	<div class="acc-crumb"><a href="<?php echo esc_url($M::url()); ?>">← <?php esc_html_e('My scripts', 'y4apk'); ?></a></div>
	<div class="acc-wiz" data-wiz data-start="<?php echo (int) $start; ?>" data-games="<?php echo esc_attr(wp_json_encode($games)); ?>">
		<div class="acc-wiz-head">
			<span class="acc-wiz-badge" data-head-icon><?php echo $icon('code'); ?></span>
			<div>
				<h1><?php echo esc_html($editing ? __('Edit your script', 'y4apk') : __('New script', 'y4apk')); ?></h1>
				<p><span data-head-game></span><?php echo esc_html($editing && $c['post']->post_status === 'publish' ? __('Live', 'y4apk') : __('Not published yet', 'y4apk')); ?></p>
			</div>
		</div>
		<div class="acc-wiz-grid">
			<aside class="acc-card acc-wiz-steps">
				<h2><?php esc_html_e('How to add your script', 'y4apk'); ?></h2>
				<p><?php esc_html_e('Follow these 4 easy steps', 'y4apk'); ?></p>
				<ol>
					<?php foreach ([1 => __('Choose game', 'y4apk'), 2 => __('Script details', 'y4apk'), 3 => __('Add the code', 'y4apk'), 4 => __('Review and send', 'y4apk')] as $n => $label) : ?>
					<li data-goto="<?php echo (int) $n; ?>"><span class="acc-wiz-num"><i><?php echo (int) $n; ?></i><?php echo $icon('check'); ?></span><span><b><?php echo esc_html($label); ?></b><small data-step-sub="<?php echo (int) $n; ?>"></small></span></li>
					<?php endforeach; ?>
				</ol>
			</aside>
			<div class="acc-card acc-wiz-main">
				<?php if ($editing && $c['post']->post_status === 'publish') : ?><div class="acc-note acc-note-warn"><?php esc_html_e('When you save, this script goes back in review and is hidden until it is checked again.', 'y4apk'); ?></div><?php endif; ?>
				<?php if ($editing && (string) get_post_meta($c['post']->ID, Y4APK_Members::REJECT, true) !== '') : ?><div class="acc-note acc-note-err"><b><?php esc_html_e('What to change:', 'y4apk'); ?></b> <?php echo esc_html(get_post_meta($c['post']->ID, Y4APK_Members::REJECT, true)); ?></div><?php endif; ?>
				<?php $messages(); ?>
				<div class="acc-note acc-note-err" data-wiz-err role="alert" hidden></div>
				<?php $form_open($editing ? 'edit' : 'submit', ' novalidate'); ?>

				<section class="acc-wiz-step" data-step="1">
					<h2 class="acc-wiz-title"><?php esc_html_e('Where does your script work?', 'y4apk'); ?></h2>
					<p class="acc-wiz-lead"><?php esc_html_e('Pick the Roblox game it is made for, or say it works in any game.', 'y4apk'); ?></p>
					<div class="acc-wiz-scope">
						<label class="acc-wiz-opt"><input type="radio" name="scope" value="game" <?php checked($scope !== 'universal'); ?>><span class="acc-wiz-opt-text"><b><?php esc_html_e('One game', 'y4apk'); ?></b><small><?php esc_html_e('The script is made for one Roblox game', 'y4apk'); ?></small></span><span class="acc-wiz-opt-art"><?php echo $icon('pad'); ?></span></label>
						<label class="acc-wiz-opt"><input type="radio" name="scope" value="universal" <?php checked($scope === 'universal'); ?>><span class="acc-wiz-opt-text"><b><?php esc_html_e('Any game', 'y4apk'); ?></b><small><?php esc_html_e('Universal: it works across Roblox games', 'y4apk'); ?></small></span><span class="acc-wiz-opt-art"><?php echo $icon('globe'); ?></span></label>
					</div>
					<div class="acc-wiz-pick" data-pick>
						<div class="acc-wiz-search">
							<?php echo $icon('search'); ?>
							<input type="text" data-search autocomplete="off" spellcheck="false" maxlength="120" placeholder="<?php esc_attr_e('Game name, roblox.com/games/… link or place ID', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Find the game', 'y4apk'); ?>" role="combobox" aria-expanded="false" aria-controls="acc-wiz-results">
							<div class="acc-wiz-results" id="acc-wiz-results" data-results role="listbox" hidden></div>
						</div>
						<div class="acc-wiz-chosen" data-chosen hidden></div>
						<?php if ($popular) : ?>
						<p class="acc-wiz-label"><?php esc_html_e('Popular games', 'y4apk'); ?></p>
						<div class="acc-wiz-popular">
							<?php foreach ($popular as $g) : ?><button type="button" class="acc-wiz-game" data-choose="<?php echo (int) $g['id']; ?>"><img src="<?php echo esc_url($g['icon'], ['http', 'https', 'data']); ?>" alt="" width="40" height="40" loading="lazy"><span><?php echo esc_html($g['name']); ?></span></button><?php endforeach; ?>
						</div>
						<?php endif; ?>
						<div class="acc-wiz-fallback">
							<div class="acc-field">
								<label for="f-game"><?php esc_html_e('Game', 'y4apk'); ?></label>
								<div class="acc-input"><select id="f-game" name="game">
									<option value="0"><?php esc_html_e('Choose a game…', 'y4apk'); ?></option>
									<?php foreach ($c['games'] as $g) : ?><option value="<?php echo esc_attr($g->term_id); ?>" <?php selected((int) $v('game'), (int) $g->term_id); ?>><?php echo esc_html($g->name); ?></option><?php endforeach; ?>
								</select></div>
							</div>
							<?php $field(__('Not in the list? Its name or roblox.com link', 'y4apk'), 'game_new', 'text', ['maxlength' => '120', 'placeholder' => 'Blox Fruits']); ?>
						</div>
					</div>
					<div class="acc-wiz-nav"><span></span><button type="button" class="acc-btn acc-btn-primary" data-next><?php esc_html_e('Continue', 'y4apk'); ?> <?php echo $icon('arrow'); ?></button></div>
				</section>

				<section class="acc-wiz-step" data-step="2">
					<h2 class="acc-wiz-title"><?php esc_html_e('Build your script page', 'y4apk'); ?></h2>
					<p class="acc-wiz-lead"><?php esc_html_e('This is how players will see it. Only the name is needed.', 'y4apk'); ?></p>
					<div class="acc-wiz-page">
						<figure class="acc-wiz-art"><img data-art src="" alt="" hidden><span class="acc-wiz-art-tag"><?php esc_html_e('Game artwork', 'y4apk'); ?></span></figure>
						<div class="acc-wiz-meta">
							<div class="acc-wiz-name">
								<input id="f-hub" type="text" name="hub" value="<?php echo esc_attr($v('hub')); ?>" maxlength="40" placeholder="<?php esc_attr_e('Name your script', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Script name', 'y4apk'); ?>" data-counted>
								<span class="acc-wiz-count" data-count-for="f-hub"></span>
							</div>
							<p class="acc-hint"><?php esc_html_e('The name its maker gave it, like Redz Hub or Speed Hub X. Made it yourself? Give it your own name.', 'y4apk'); ?></p>
							<div class="acc-wiz-row"><img data-game-icon src="" alt="" width="28" height="28" hidden><span data-game-name></span><button type="button" class="acc-wiz-link" data-goto="1"><?php esc_html_e('Change', 'y4apk'); ?></button></div>
							<div class="acc-wiz-row"><?php echo $M::avatar($me->user_login, 28); ?><span><b>@<?php echo esc_html($me->user_login); ?></b><small><?php esc_html_e('Not published yet', 'y4apk'); ?></small></span></div>
							<p class="acc-wiz-small"><?php esc_html_e('Be honest: turn this on if players need a key to use it.', 'y4apk'); ?></p>
							<div class="acc-wiz-chips">
								<input type="hidden" name="keysys" value="0">
								<label class="acc-chip"><input type="checkbox" name="keysys" value="1" <?php checked($keysys); ?>><span><?php echo $icon('key'); ?><?php esc_html_e('Key system', 'y4apk'); ?></span></label>
							</div>
						</div>
					</div>
					<div class="acc-field acc-wiz-about">
						<label for="f-desc"><?php esc_html_e('About this script', 'y4apk'); ?> <small><?php esc_html_e('(optional)', 'y4apk'); ?></small></label>
						<div class="acc-input"><textarea id="f-desc" name="desc" rows="4" maxlength="600" placeholder="<?php esc_attr_e('What it does and how to use it: auto farm, teleport, fruit sniper…', 'y4apk'); ?>" data-counted><?php echo esc_textarea($v('desc')); ?></textarea></div>
						<span class="acc-wiz-count" data-count-for="f-desc"></span>
					</div>
					<div class="acc-title-preview" data-title-preview hidden><span><?php esc_html_e('Your post will be called', 'y4apk'); ?></span><b data-title-text></b></div>
					<div class="acc-wiz-nav"><button type="button" class="acc-btn acc-btn-ghost" data-back><?php echo $icon('back'); ?> <?php esc_html_e('Back', 'y4apk'); ?></button><button type="button" class="acc-btn acc-btn-primary" data-next><?php esc_html_e('Next: add your code', 'y4apk'); ?> <?php echo $icon('arrow'); ?></button></div>
				</section>

				<section class="acc-wiz-step" data-step="3">
					<h2 class="acc-wiz-title"><?php esc_html_e('Add your code', 'y4apk'); ?></h2>
					<p class="acc-wiz-lead"><?php esc_html_e('Paste the loadstring line players run in their executor.', 'y4apk'); ?></p>
					<div data-codes>
					<?php foreach ($codes as $row) : ?>
						<div class="acc-code" data-code>
							<div class="acc-code-head"><input class="acc-code-label" type="text" name="label[]" value="<?php echo esc_attr($row['label']); ?>" maxlength="30" placeholder="<?php esc_attr_e('Name, like Mobile (optional)', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Code name', 'y4apk'); ?>"><span class="acc-wiz-count" data-code-count></span></div>
							<textarea name="code[]" rows="5" maxlength="4000" spellcheck="false" placeholder='-- Paste your script here&#10;loadstring(game:HttpGet("https://…"))()' aria-label="<?php esc_attr_e('Code', 'y4apk'); ?>"><?php echo esc_textarea($row['code']); ?></textarea>
						</div>
					<?php endforeach; ?>
					</div>
					<button type="button" class="acc-add" data-add-code <?php echo count($codes) >= 3 ? 'hidden' : ''; ?>>+ <?php esc_html_e('Add another code (Thai, mobile…)', 'y4apk'); ?></button>
					<template id="acc-code-tpl"><div class="acc-code" data-code><div class="acc-code-head"><input class="acc-code-label" type="text" name="label[]" maxlength="30" placeholder="<?php esc_attr_e('Name, like Mobile (optional)', 'y4apk'); ?>"><span class="acc-wiz-count" data-code-count></span></div><textarea name="code[]" rows="5" maxlength="4000" spellcheck="false" placeholder='loadstring(game:HttpGet("https://…"))()'></textarea></div></template>
					<p class="acc-hint"><?php esc_html_e('Only the code goes here: no links to other sites and no ads. Scripts that steal accounts are removed and the account is closed.', 'y4apk'); ?></p>
					<div class="acc-wiz-nav"><button type="button" class="acc-btn acc-btn-ghost" data-back><?php echo $icon('back'); ?> <?php esc_html_e('Back', 'y4apk'); ?></button><button type="button" class="acc-btn acc-btn-primary" data-next><?php esc_html_e('Continue to review', 'y4apk'); ?> <?php echo $icon('arrow'); ?></button></div>
				</section>

				<section class="acc-wiz-step" data-step="4">
					<h2 class="acc-wiz-title"><?php esc_html_e('Review and send', 'y4apk'); ?></h2>
					<p class="acc-wiz-lead"><?php esc_html_e('One more look. It stays private until we check it, usually within a day.', 'y4apk'); ?></p>
					<div class="acc-wiz-review" data-review>
						<div class="acc-wiz-review-art"><img data-review-art src="" alt="" hidden><span class="acc-wiz-review-key" data-review-key hidden><?php echo $icon('key'); ?><?php esc_html_e('Keyless', 'y4apk'); ?></span></div>
						<div class="acc-wiz-review-body">
							<b class="acc-wiz-review-title" data-review-title></b>
							<span class="acc-wiz-review-who"><img data-review-icon src="" alt="" width="20" height="20" hidden><span data-review-game></span> · @<?php echo esc_html($me->user_login); ?></span>
							<p data-review-desc></p>
							<pre class="acc-wiz-review-code" data-review-code></pre>
						</div>
					</div>
					<ul class="acc-wiz-rules">
						<li><?php esc_html_e('We check every script before it goes live, and email you when it does.', 'y4apk'); ?></li>
						<li><?php esc_html_e('Keep the link working: scripts that stop working get removed.', 'y4apk'); ?></li>
					</ul>
					<?php echo $M::trap_fields(); $M::turnstile_widget(); ?>
					<div class="acc-wiz-nav"><button type="button" class="acc-btn acc-btn-ghost" data-back><?php echo $icon('back'); ?> <?php esc_html_e('Back', 'y4apk'); ?></button><button class="acc-btn acc-btn-primary" type="submit"><?php echo esc_html($editing ? __('Save and send for review', 'y4apk') : __('Send for review', 'y4apk')); ?></button></div>
				</section>
				</form>
			</div>
		</div>
	</div>
	<?php // Named y4apk-pwa-…: the speed plugin's delay already leaves y4apk-pwa scripts alone, so the steps work at once. ?>
	<script id="y4apk-pwa-upload" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
	(function () {
		var w = document.querySelector('[data-wiz]');
		if (!w || !w.querySelector('form')) { return; }
		var f = w.querySelector('form'), games = [];
		try { games = JSON.parse(w.getAttribute('data-games') || '[]'); } catch (e) {}
		var T = <?php echo wp_json_encode([
			'game'    => __('Choose the game this script is for, or type its name.', 'y4apk'),
			'name'    => __('Give the script a name, like “Redz Hub”.', 'y4apk'),
			'code'    => __('Paste the script’s code.', 'y4apk'),
			'scripts' => __('%d scripts', 'y4apk'),
			'one'     => __('1 script', 'y4apk'),
			'use'     => __('Use “%s”', 'y4apk'),
			'newgame' => __('A game not on the site yet: we add it when we check your script.', 'y4apk'),
			'link'    => __('Roblox game %s', 'y4apk'),
			'nomatch' => __('No game with that name here yet.', 'y4apk'),
			'change'  => __('Change', 'y4apk'),
			'any'     => __('Universal', 'y4apk'),
			'anysub'  => __('Works in any game', 'y4apk'),
		]); ?>;
		var $ = function (s, el) { return (el || w).querySelector(s); }, $$ = function (s, el) { return [].slice.call((el || w).querySelectorAll(s)); };
		var steps = $$('[data-step]'), err = $('[data-wiz-err]'), sel = $('#f-game'), gnew = $('#f-game_new'), search = $('[data-search]'), results = $('[data-results]'), chosen = $('[data-chosen]'), hub = $('#f-hub');
		var cur = 1, reached = +w.getAttribute('data-start') || 1, hit = -1;
		w.classList.add('is-js');

		function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
		function scope() { var r = $('[name="scope"]:checked'); return r ? r.value : 'game'; }
		function place(t) { var m = /^\d{3,15}$/.test(t) ? [0, t] : t.match(/roblox\.com\/(?:[a-z]{2}(?:-[a-z]{2})?\/)?games\/(\d{3,15})/i) || t.match(/[?&]placeId=(\d{3,15})/i); return m ? m[1] : ''; }
		function nameOf(t) {
			var p = place(t);
			if (!p) { return t; }
			var m = t.match(/games\/\d+\/([^/?#]+)/i);
			return m ? decodeURIComponent(m[1]).replace(/[-_]+/g, ' ').trim() : T.link.replace('%s', p);
		}
		function byId(id) { for (var i = 0; i < games.length; i++) { if (games[i].id === id) { return games[i]; } } return null; }
		function choice() {
			if (scope() === 'universal') { return {name: T.any, icon: '', art: '', any: true}; }
			var g = byId(+sel.value);
			if (g) { return g; }
			var t = gnew.value.trim();
			return t ? {name: nameOf(t), icon: '', art: '', wanted: true} : null;
		}
		function keyless() { var k = f.querySelector('input[type="checkbox"][name="keysys"]'); return !(k && k.checked); }
		function codes() { return $$('[data-code] textarea').map(function (t) { return t.value.trim(); }).filter(Boolean); }
		function problem(n) {
			if (n === 1 && !choice()) { return T.game; }
			if (n === 2 && hub.value.trim().length < 2) { return T.name; }
			if (n === 3 && !codes().length) { return T.code; }
			return '';
		}
		function say(m) { err.textContent = m; err.hidden = !m; if (m) { err.scrollIntoView({block: 'nearest', behavior: 'smooth'}); } }
		function title() { var g = choice(); return (g ? g.name : '…') + ' Script ' + hub.value.trim() + (keyless() ? ' (No Key)' : ''); }

		function paint() {
			var g = choice(), art = g && g.art ? g.art : '', icon = g && g.icon ? g.icon : '';
			// the game: in the head, the stepper, step 2 and the review
			$('[data-head-game]').textContent = g ? g.name + ' · ' : '';
			$('[data-head-icon]').innerHTML = icon ? '<img src="' + esc(icon) + '" alt="">' : $('[data-head-icon]').getAttribute('data-svg');
			$('[data-step-sub="1"]').textContent = g && reached > 1 ? g.name : '';
			$('[data-step-sub="2"]').textContent = reached > 2 ? hub.value.trim() : '';
			var n = codes().length; $('[data-step-sub="3"]').textContent = reached > 3 && n ? (n === 1 ? '1 code' : n + ' codes') : '';
			[['[data-art]', art], ['[data-review-art]', art], ['[data-game-icon]', icon], ['[data-review-icon]', icon]].forEach(function (p) { var i = $(p[0]); i.hidden = !p[1]; if (p[1]) { i.src = p[1]; } });
			$('[data-game-name]').textContent = g ? g.name : '';
			$('[data-review-game]').textContent = g ? g.name : '';
			$('[data-title-text]').textContent = title();
			$('[data-title-preview]').hidden = hub.value.trim() === '';
			$('[data-review-title]').textContent = title();
			$('[data-review-key]').hidden = !keyless();
			$('[data-review-desc]').textContent = $('#f-desc').value.trim();
			var first = codes()[0] || '';
			$('[data-review-code]').textContent = first.split('\n').slice(0, 3).join('\n');
			$$('[data-counted]').forEach(function (i) { var c = $('[data-count-for="' + i.id + '"]'); if (c) { c.textContent = i.value.length + '/' + i.maxLength; } });
			$$('[data-code]').forEach(function (b) { var t = $('textarea', b), c = $('[data-code-count]', b); if (c) { c.textContent = t.value.length + '/4000'; } });
			$('[data-pick]').hidden = scope() === 'universal';
			$$('.acc-wiz-opt').forEach(function (o) { o.classList.toggle('is-on', $('input', o).checked); });
			// the chosen game, under the search
			if (g && !g.any) {
				chosen.innerHTML = (g.icon ? '<img src="' + esc(g.icon) + '" alt="" width="44" height="44">' : '<span class="acc-wiz-q">?</span>')
					+ '<span><b>' + esc(g.name) + '</b><small>' + esc(g.wanted ? T.newgame : (g.count === 1 ? T.one : T.scripts.replace('%d', g.count)) + (g.place ? ' · ID ' + g.place : '')) + '</small></span>'
					+ '<button type="button" class="acc-wiz-link" data-unchoose>' + esc(T.change) + '</button>';
				chosen.hidden = false; search.parentNode.hidden = true;
			} else {
				chosen.hidden = true; search.parentNode.hidden = false;
			}
			$$('[data-choose]').forEach(function (b) { b.classList.toggle('is-on', +b.getAttribute('data-choose') === +sel.value && scope() !== 'universal'); });
		}
		function show(n, focus) {
			cur = n; reached = Math.max(reached, n);
			steps.forEach(function (s) { s.hidden = +s.getAttribute('data-step') !== n; });
			$$('.acc-wiz-steps li').forEach(function (li) {
				var k = +li.getAttribute('data-goto');
				li.classList.toggle('is-on', k === n);
				li.classList.toggle('is-done', k < reached && k !== n && !problem(k));
				li.classList.toggle('can-go', k <= reached);
			});
			paint();
			if (focus) {
				var top = w.getBoundingClientRect().top + window.pageYOffset - 90;
				if (window.pageYOffset > top) { window.scrollTo({top: top, behavior: 'smooth'}); }
				var i = n === 1 ? (search.parentNode.hidden ? null : search) : n === 2 ? hub : n === 3 ? $('[data-code] textarea') : null;
				if (i) { i.focus({preventScroll: true}); }
			}
		}
		function go(n) {
			for (var k = 1; k < n; k++) { var p = problem(k); if (p) { show(k, true); say(p); return; } }
			say(''); show(n, true);
		}
		function choose(id) { sel.value = String(id); gnew.value = ''; search.value = ''; close(); say(''); paint(); }
		function wanted(t) { sel.value = '0'; gnew.value = t; search.value = ''; close(); say(''); paint(); }
		function close() { results.hidden = true; search.setAttribute('aria-expanded', 'false'); hit = -1; }

		function find() {
			var t = search.value.trim(), q = t.toLowerCase(), p = place(t), rows = [];
			if (!t) { close(); return; }
			games.forEach(function (g) {
				var at = g.name.toLowerCase().indexOf(q);
				if ((p && g.place === p) || (!p && at > -1)) { rows.push([p ? -1 : at, g]); }
			});
			rows.sort(function (a, b) { return a[0] - b[0] || b[1].count - a[1].count; });
			var html = rows.slice(0, 8).map(function (r) {
				var g = r[1];
				return '<button type="button" role="option" class="acc-wiz-hit" data-pick-id="' + g.id + '"><img src="' + esc(g.icon) + '" alt="" width="36" height="36"><span><b>' + esc(g.name) + '</b><small>'
					+ (g.place ? 'ID: ' + esc(g.place) + ' · ' : '') + esc(g.count === 1 ? T.one : T.scripts.replace('%d', g.count)) + '</small></span></button>';
			}).join('');
			var exact = rows.some(function (r) { return r[1].name.toLowerCase() === q || (p && r[1].place === p); });
			if (!exact && t.length >= 2) {
				html += '<button type="button" role="option" class="acc-wiz-hit acc-wiz-hit-new" data-pick-new><span class="acc-wiz-q">+</span><span><b>' + esc(T.use.replace('%s', nameOf(t))) + '</b><small>' + esc(T.newgame) + '</small></span></button>';
			}
			results.innerHTML = html || '<p class="acc-wiz-none">' + esc(T.nomatch) + '</p>';
			results.hidden = false; search.setAttribute('aria-expanded', 'true'); hit = -1;
		}

		$('[data-head-icon]').setAttribute('data-svg', $('[data-head-icon]').innerHTML);
		search.addEventListener('input', find);
		search.addEventListener('focus', function () { if (search.value.trim()) { find(); } });
		search.addEventListener('keydown', function (e) {
			var opts = $$('[role="option"]', results);
			if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
				e.preventDefault();
				if (!opts.length) { return; }
				hit = (hit + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
				opts.forEach(function (o, i) { o.classList.toggle('is-hit', i === hit); });
			} else if (e.key === 'Enter') {
				e.preventDefault();
				var o = opts[hit > -1 ? hit : 0];
				if (o) { o.click(); }
			} else if (e.key === 'Escape') {
				close();
			}
		});
		document.addEventListener('click', function (e) { if (!e.target.closest('.acc-wiz-search')) { close(); } });
		w.addEventListener('click', function (e) {
			var t = e.target.closest('[data-pick-id],[data-pick-new],[data-choose],[data-unchoose],[data-next],[data-back],[data-goto]');
			if (!t) { return; }
			if (t.hasAttribute('data-pick-id')) { choose(+t.getAttribute('data-pick-id')); }
			else if (t.hasAttribute('data-pick-new')) { wanted(search.value.trim()); }
			else if (t.hasAttribute('data-choose')) { var r = $('[name="scope"][value="game"]'); r.checked = true; choose(+t.getAttribute('data-choose')); }
			else if (t.hasAttribute('data-unchoose')) { sel.value = '0'; gnew.value = ''; paint(); search.focus(); }
			else if (t.hasAttribute('data-next')) { var p = problem(cur); if (p) { say(p); } else { go(cur + 1); } }
			else if (t.hasAttribute('data-back')) { say(''); show(cur - 1, true); }
			else if (t.hasAttribute('data-goto')) { var n = +t.getAttribute('data-goto'); if (n <= reached || n === cur + 1) { go(n); } }
		});
		f.addEventListener('input', function () { paint(); if (!err.hidden && !problem(cur)) { say(''); } });
		f.addEventListener('change', function () { paint(); if (!err.hidden && !problem(cur)) { say(''); } });
		f.addEventListener('submit', function (e) {
			for (var k = 1; k <= 3; k++) {
				var p = problem(k);
				if (p) { e.preventDefault(); e.stopImmediatePropagation(); show(k, true); say(p); return; }
			}
		});
		show(Math.min(4, Math.max(1, reached)), false);
	})();
	</script>
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
