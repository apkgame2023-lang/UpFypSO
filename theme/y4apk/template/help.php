<?php
/**
 * Terms of Service, Privacy Policy, DMCA Policy, Copyright, Contact, Feedback
 * (Y4APK_Help, 2.52). The page is the same for everyone (a page cache keeps
 * it): who is signed in is read from the y4apk_me cookie, and the forms send
 * with AJAX.
 */
defined('ABSPATH') || exit;

$key = Y4APK_Help::$page;
$pages = Y4APK_Help::pages();
$P = $pages[$key];
$legal = in_array($key, Y4APK_Help::legal(), true);
$members = class_exists('Y4APK_Members');
$links = [
	'{terms}'     => Y4APK_Help::url('terms'),
	'{privacy}'   => Y4APK_Help::url('privacy'),
	'{dmca}'      => Y4APK_Help::url('dmca'),
	'{copyright}' => Y4APK_Help::url('copyright'),
	'{contact}'   => Y4APK_Help::url('contact'),
	'{feedback}'  => Y4APK_Help::url('feedback'),
	'{settings}'  => $members ? Y4APK_Members::url('settings') : home_url('/'),
];
$fill = function ($html) use ($links) {
	return wp_kses(strtr((string) $html, array_map('esc_url', $links)), ['a' => ['href' => []], 'b' => []]);
};
$ic = [
	'doc'       => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm-1 7V3.5L18.5 9H13ZM8 13h8v2H8v-2Zm0 4h8v2H8v-2Zm0-8h3v2H8V9Z"/>',
	'shield'    => '<path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1.2 14.2-3.5-3.5 1.4-1.4 2.1 2.1 4.9-4.9 1.4 1.4-6.3 6.3Z"/>',
	'scale'     => '<path d="M13 3.1V5h5.3l-2.8 6.5c.4.9 1.5 1.5 2.5 1.5s2.1-.6 2.5-1.5L17.7 5H21V3h-8V3.1ZM18 7.6l1.6 3.9h-3.2L18 7.6ZM11 3H3v2h3.3l-2.8 6.5C3.9 12.4 5 13 6 13s2.1-.6 2.5-1.5L5.7 5H11V3ZM6 7.6l1.6 3.9H4.4L6 7.6ZM11 6h2v13h4v2H7v-2h4V6Z"/>',
	'copyright' => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm-.1-11.5c1 0 1.7.5 2 1.3h2.1c-.4-2-2-3.3-4.1-3.3-2.5 0-4.4 2-4.4 4.5s1.9 4.5 4.4 4.5c2.1 0 3.7-1.3 4.1-3.3h-2.1c-.3.8-1 1.3-2 1.3-1.3 0-2.3-1.1-2.3-2.5s1-2.5 2.3-2.5Z"/>',
	'mail'      => '<path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/>',
	'chat'      => '<path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2ZM7 9h10v2H7V9Zm6 5H7v-2h6v2Zm4-6H7V6h10v2Z"/>',
	'check'     => '<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/>',
	'send'      => '<path d="M2 21 23 12 2 3v7l15 2-15 2v7Z"/>',
	'help'      => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 17h-2v-2h2v2Zm2.1-7.8-.9.9c-.7.7-1.2 1.3-1.2 2.9h-2v-.5c0-1.1.5-2.1 1.2-2.8l1.2-1.3c.4-.4.6-.9.6-1.4a2 2 0 0 0-4 0H8a4 4 0 0 1 8 0c0 .9-.4 1.7-.9 2.2Z"/>',
	'bug'       => '<path d="M20 8h-2.8a6 6 0 0 0-1.8-2l1.6-1.6L15.6 3l-2.2 2.2a5.9 5.9 0 0 0-2.8 0L8.4 3 7 4.4 8.6 6c-.8.5-1.4 1.2-1.8 2H4v2h2.1c-.1.3-.1.7-.1 1v1H4v2h2v1c0 .3 0 .7.1 1H4v2h2.8a6 6 0 0 0 10.4 0H20v-2h-2.1c.1-.3.1-.7.1-1v-1h2v-2h-2v-1c0-.3 0-.7-.1-1H20V8Zm-6 8h-4v-2h4v2Zm0-4h-4v-2h4v2Z"/>',
	'user'      => '<path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-4.4 0-8 2.2-8 5v3h16v-3c0-2.8-3.6-5-8-5Z"/>',
	'megaphone' => '<path d="M18 11v2h4v-2h-4Zm-2 6.6 1.2 1.6 3.2-2.4-1.2-1.6-3.2 2.4ZM20.4 5.2 19.2 3.6 16 6l1.2 1.6 3.2-2.4ZM4 9a2 2 0 0 0-2 2v2a2 2 0 0 0 2 2h1v4h2v-4h1l5 3V6L8 9H4Zm11.5 3c0-1.3-.6-2.5-1.5-3.4v6.8c.9-.8 1.5-2 1.5-3.4Z"/>',
	'calendar'  => '<path d="M19 4h-1V2h-2v2H8V2H6v2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 16H5V10h14v10Z"/>',
	'clock'     => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm0 18a8 8 0 1 1 0-16 8 8 0 0 1 0 16Zm.5-13H11v6l5.2 3.2.8-1.3-4.5-2.7V7Z"/>',
	'list'      => '<path d="M3 13h2v-2H3v2Zm0 4h2v-2H3v2Zm0-8h2V7H3v2Zm4 4h14v-2H7v2Zm0 4h14v-2H7v2ZM7 7v2h14V7H7Z"/>',
	'arrow'     => '<path d="M12 4 10.6 5.4 16.2 11H4v2h12.2l-5.6 5.6L12 20l8-8-8-8Z"/>',
	'alert'     => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm1 15h-2v-2h2v2Zm0-4h-2V7h2v6Z"/>',
	'lock'      => '<path d="M18 8h-1V6A5 5 0 0 0 7 6v2H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V10a2 2 0 0 0-2-2Zm-6 9a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm3.1-9H8.9V6a3.1 3.1 0 0 1 6.2 0v2Z"/>',
];
$svg = function ($name, $size = 20) use ($ic) {
	return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true">' . ($ic[$name] ?? '') . '</svg>';
};
$field = function ($label, $name, $attrs = [], $hint = '', $optional = false) {
	$id = 'y4h-' . $name;
	echo '<div class="y4h-field" data-f="' . esc_attr($name) . '"><label for="' . esc_attr($id) . '">' . esc_html($label) . ($optional ? ' <span class="y4h-opt">' . esc_html__('optional', 'y4apk') . '</span>' : '') . '</label>';
	$tag = !empty($attrs['_textarea']) ? 'textarea' : 'input';
	unset($attrs['_textarea']);
	echo '<' . $tag . ' id="' . esc_attr($id) . '" name="' . esc_attr($name) . '"';
	foreach ($attrs as $k => $v) {
		echo $v === true ? ' ' . esc_attr($k) : ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
	}
	echo $tag === 'textarea' ? '></textarea>' : '>';
	if ($hint !== '') {
		echo '<p class="y4h-hint">' . esc_html($hint) . '</p>';
	}
	echo '</div>';
};
$hidden = function ($kind) use ($members) {
	echo '<input type="hidden" name="kind" value="' . esc_attr($kind) . '"><input type="hidden" name="page" value="">';
	if ($members) {
		echo Y4APK_Members::trap_fields(); // escaped there
	}
};
$send_button = function ($label) use ($svg) {
	echo '<p class="y4h-err" role="alert" hidden></p><button class="y4h-btn" type="submit">' . $svg('send', 18) . '<span>' . esc_html($label) . '</span></button>';
};
$fine = function () use ($fill) {
	echo '<p class="y4h-fine">' . $fill(__('We use what you send only to answer you, as our <a href="{privacy}">Privacy Policy</a> says.', 'y4apk')) . '</p>';
};
$words = 0;
if ($legal) {
	foreach (Y4APK_Help_Texts::sections($key) as $s) {
		foreach (array_merge($s['p'] ?? [], $s['li'] ?? [], $s['after'] ?? []) as $t) {
			$words += str_word_count(wp_strip_all_tags($t));
		}
	}
}
get_header();
?>
<main class="y4h y4h-<?php echo esc_attr($key); ?>">
	<div class="wrp">
		<section class="y4h-hero y4h-tint-<?php echo esc_attr($P['tint']); ?>">
			<span class="y4h-hero-ic"><?php echo $svg($P['icon'], 28); ?></span>
			<div class="y4h-hero-t">
				<span class="y4h-tag"><?php echo esc_html($P['tag']); ?></span>
				<h1><?php echo esc_html($P['h1'] ?? $P['title']); ?></h1>
				<p><?php echo esc_html($P['lead']); ?></p>
				<?php if ($legal) : ?>
					<div class="y4h-meta">
						<span><?php echo $svg('calendar', 15); ?><?php echo esc_html(sprintf(__('Last updated %s', 'y4apk'), wp_date(get_option('date_format'), strtotime(Y4APK_Help::UPDATED . ' 12:00:00 UTC')))); ?></span>
						<span><?php echo $svg('clock', 15); ?><?php echo esc_html(sprintf(__('%s min read', 'y4apk'), number_format_i18n(max(1, (int) round($words / 200))))); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<span class="y4h-hero-bg" aria-hidden="true"><?php echo $svg($P['icon'], 150); ?></span>
		</section>

		<?php if ($legal) :
			$sections = Y4APK_Help_Texts::sections($key);
			?>
			<nav class="y4h-tabs" aria-label="<?php esc_attr_e('Policies', 'y4apk'); ?>">
				<?php foreach (Y4APK_Help::legal() as $k) : ?>
					<a href="<?php echo esc_url(Y4APK_Help::url($k)); ?>"<?php echo $k === $key ? ' aria-current="page" class="is-on"' : ''; ?>><?php echo $svg($pages[$k]['icon'], 16); ?><?php echo esc_html($pages[$k]['title']); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="y4h-legal">
				<aside class="y4h-toc">
					<details class="y4h-toc-in" open>
						<summary><?php echo $svg('list', 18); ?><b><?php esc_html_e('On this page', 'y4apk'); ?></b><span class="y4h-count"><?php echo esc_html(number_format_i18n(count($sections))); ?></span></summary>
						<ol>
							<?php foreach ($sections as $i => $s) : ?>
								<li><a href="#<?php echo esc_attr($s['id']); ?>"><span><?php echo esc_html(sprintf('%02d', $i + 1)); ?></span><?php echo esc_html($s['t']); ?></a></li>
							<?php endforeach; ?>
						</ol>
					</details>
				</aside>
				<article class="y4h-doc">
					<section class="y4h-short">
						<h2 class="y4h-eyebrow"><?php esc_html_e('The short version', 'y4apk'); ?></h2>
						<div class="y4h-short-grid">
							<?php foreach (Y4APK_Help_Texts::short($key) as $card) : ?>
								<div class="y4h-short-card"><span class="y4h-tick"><?php echo $svg('check', 16); ?></span><div><b><?php echo esc_html($card[0]); ?></b><span><?php echo esc_html($card[1]); ?></span></div></div>
							<?php endforeach; ?>
						</div>
					</section>
					<?php foreach ($sections as $i => $s) : ?>
						<section class="y4h-sec" id="<?php echo esc_attr($s['id']); ?>">
							<h2><span class="y4h-n"><?php echo esc_html(sprintf('%02d', $i + 1)); ?></span><?php echo esc_html($s['t']); ?></h2>
							<?php foreach ($s['p'] ?? [] as $t) : ?><p><?php echo $fill($t); ?></p><?php endforeach; ?>
							<?php if (!empty($s['li'])) : ?><ul><?php foreach ($s['li'] as $t) : ?><li><?php echo $fill($t); ?></li><?php endforeach; ?></ul><?php endif; ?>
							<?php foreach ($s['after'] ?? [] as $t) : ?><p><?php echo $fill($t); ?></p><?php endforeach; ?>
						</section>
					<?php endforeach; ?>
					<?php if ($key === 'dmca') : ?>
						<a class="y4h-cta" href="<?php echo esc_url(Y4APK_Help::url('copyright')); ?>"><span class="y4h-cta-ic"><?php echo $svg('copyright', 22); ?></span><span><b><?php esc_html_e('Send a copyright notice', 'y4apk'); ?></b><small><?php esc_html_e('The form asks for everything the law needs.', 'y4apk'); ?></small></span><?php echo $svg('arrow', 20); ?></a>
					<?php else : ?>
						<a class="y4h-cta" href="<?php echo esc_url(Y4APK_Help::url('contact')); ?>"><span class="y4h-cta-ic"><?php echo $svg('mail', 22); ?></span><span><b><?php esc_html_e('Still have a question?', 'y4apk'); ?></b><small><?php esc_html_e('Send us a message: a person reads every one.', 'y4apk'); ?></small></span><?php echo $svg('arrow', 20); ?></a>
					<?php endif; ?>
				</article>
			</div>

		<?php elseif ($key === 'contact') : ?>
			<div class="y4h-split">
				<section class="y4h-card">
					<form class="y4h-form" data-y4h-form novalidate>
						<h2 class="y4h-form-h"><?php echo $svg('send', 20); ?><?php esc_html_e('Send us a message', 'y4apk'); ?></h2>
						<fieldset class="y4h-topics">
							<legend><?php esc_html_e('What’s it about?', 'y4apk'); ?></legend>
							<div class="y4h-topic-grid">
								<?php $first = true; foreach (Y4APK_Help::topics() as $tk => $t) : ?>
									<label class="y4h-topic"><input type="radio" name="topic" value="<?php echo esc_attr($tk); ?>"<?php echo $first ? ' checked' : ''; ?>><span><?php echo $svg($t[1], 18); ?><?php echo esc_html($t[0]); ?></span></label>
								<?php $first = false; endforeach; ?>
							</div>
						</fieldset>
						<div class="y4h-as" data-y4h-as hidden><span class="y4h-as-av" data-y4h-av></span><span><b data-y4h-name></b><small><?php esc_html_e('Replies go to your account’s email.', 'y4apk'); ?></small></span></div>
						<div class="y4h-copy-note" data-y4h-copy hidden>
							<?php echo $svg('copyright', 22); ?>
							<div><b><?php esc_html_e('Copyright notices have their own form', 'y4apk'); ?></b><span><?php esc_html_e('It asks for everything the law needs, so we can act on it at once. For a counter-notice, write below.', 'y4apk'); ?></span>
								<a class="y4h-btn y4h-btn-ghost" href="<?php echo esc_url(Y4APK_Help::url('copyright')); ?>"><?php esc_html_e('Open the copyright form', 'y4apk'); ?></a></div>
						</div>
						<div class="y4h-two" data-y4h-guest>
							<?php $field(__('Your name', 'y4apk'), 'name', ['type' => 'text', 'maxlength' => '80', 'autocomplete' => 'name']); ?>
							<?php $field(__('Email', 'y4apk'), 'email', ['type' => 'email', 'maxlength' => '100', 'autocomplete' => 'email', 'required' => true], __('So we can answer. It is never shown.', 'y4apk')); ?>
						</div>
						<?php $field(__('Link', 'y4apk'), 'link', ['type' => 'url', 'maxlength' => '500', 'placeholder' => 'https://', 'inputmode' => 'url'], __('The page on Y4APK this is about, if there is one.', 'y4apk'), true); ?>
						<div class="y4h-field" data-f="message"><label for="y4h-message"><?php esc_html_e('Message', 'y4apk'); ?> <span class="y4h-counter" data-count="5000">0 / 5,000</span></label>
							<textarea id="y4h-message" name="message" rows="7" maxlength="5000" required placeholder="<?php esc_attr_e('Tell us what happened, or what you would like…', 'y4apk'); ?>"></textarea></div>
						<?php $hidden('contact'); ?>
						<div data-y4h-ts><?php if ($members) { Y4APK_Members::turnstile_widget(); } ?></div>
						<?php $send_button(__('Send message', 'y4apk')); $fine(); ?>
					</form>
					<div class="y4h-done" data-y4h-done hidden><span class="y4h-done-ic"><?php echo $svg('check', 30); ?></span><h2><?php esc_html_e('Message sent', 'y4apk'); ?></h2>
						<p><?php esc_html_e('Thanks for writing. A person reads every message, and we answer by email.', 'y4apk'); ?></p><p class="y4h-ref"><?php esc_html_e('Your message’s number:', 'y4apk'); ?> <b data-y4h-ref></b></p></div>
				</section>
				<aside class="y4h-side">
					<div class="y4h-card y4h-tips">
						<h3><?php esc_html_e('Before you write', 'y4apk'); ?></h3>
						<ul>
							<li><?php echo $fill(__('<b>About something on Y4APK?</b> Add its link, and tell us what is wrong with it.', 'y4apk')); ?></li>
							<li><?php echo $fill(__('<b>A copyright notice?</b> Use the <a href="{copyright}">copyright form</a>: it asks for everything we need.', 'y4apk')); ?></li>
							<li><?php echo $fill(__('<b>A privacy request?</b> Say what you would like, such as a copy of your data or deleting your account. We may need to confirm it is really you.', 'y4apk')); ?></li>
							<li><?php echo $fill(__('<b>Never send your password.</b> Nobody from Y4APK will ever ask you for it.', 'y4apk')); ?></li>
						</ul>
					</div>
					<div class="y4h-card y4h-mine" data-y4h-mine>
						<h3><?php echo $svg('list', 18); ?><?php esc_html_e('Your requests', 'y4apk'); ?></h3>
						<p class="y4h-muted" data-y4h-mine-guest><?php echo wp_kses(sprintf(__('<a href="%s">Sign in</a> to see the messages you sent and our answers here.', 'y4apk'), esc_url($members ? Y4APK_Members::url('login') : wp_login_url())), ['a' => ['href' => []]]); ?></p>
						<ul class="y4h-mine-list" data-y4h-mine-list hidden></ul>
					</div>
					<div class="y4h-card y4h-more">
						<h3><?php esc_html_e('Find answers', 'y4apk'); ?></h3>
						<a href="<?php echo esc_url(Y4APK_Help::url('feedback')); ?>"><?php echo $svg('chat', 18); ?><?php esc_html_e('Send feedback', 'y4apk'); ?></a>
						<a href="<?php echo esc_url(Y4APK_Help::url('dmca')); ?>"><?php echo $svg('scale', 18); ?><?php esc_html_e('DMCA Policy', 'y4apk'); ?></a>
						<a href="<?php echo esc_url(Y4APK_Help::url('privacy')); ?>"><?php echo $svg('shield', 18); ?><?php esc_html_e('Privacy Policy', 'y4apk'); ?></a>
						<a href="<?php echo esc_url(Y4APK_Help::url('terms')); ?>"><?php echo $svg('doc', 18); ?><?php esc_html_e('Terms of Service', 'y4apk'); ?></a>
					</div>
				</aside>
			</div>

		<?php elseif ($key === 'copyright') : ?>
			<div class="y4h-split">
				<section class="y4h-card">
					<form class="y4h-form" data-y4h-form novalidate>
						<h2 class="y4h-form-h"><?php echo $svg('copyright', 20); ?><?php esc_html_e('Copyright notice', 'y4apk'); ?></h2>
						<div class="y4h-as" data-y4h-as hidden><span class="y4h-as-av" data-y4h-av></span><span><b data-y4h-name></b><small><?php esc_html_e('Replies go to your account’s email.', 'y4apk'); ?></small></span></div>
						<h3 class="y4h-step"><span>1</span><?php esc_html_e('About you', 'y4apk'); ?></h3>
						<div class="y4h-two">
							<?php $field(__('Your full name', 'y4apk'), 'name', ['type' => 'text', 'maxlength' => '80', 'autocomplete' => 'name', 'required' => true]); ?>
							<?php $field(__('Company or organisation', 'y4apk'), 'company', ['type' => 'text', 'maxlength' => '120', 'autocomplete' => 'organization'], '', true); ?>
						</div>
						<div data-y4h-guest><?php $field(__('Email', 'y4apk'), 'email', ['type' => 'email', 'maxlength' => '100', 'autocomplete' => 'email', 'required' => true], __('We answer here. It is not given to the uploader.', 'y4apk')); ?></div>
						<fieldset class="y4h-radios"><legend><?php esc_html_e('You are', 'y4apk'); ?></legend>
							<label><input type="radio" name="role" value="owner" checked> <span><?php esc_html_e('The copyright owner', 'y4apk'); ?></span></label>
							<label><input type="radio" name="role" value="agent"> <span><?php esc_html_e('Allowed to act for the owner', 'y4apk'); ?></span></label>
						</fieldset>
						<h3 class="y4h-step"><span>2</span><?php esc_html_e('The work and the links', 'y4apk'); ?></h3>
						<?php $field(__('Your work', 'y4apk'), 'work', ['_textarea' => true, 'rows' => '3', 'maxlength' => '2000', 'required' => true, 'placeholder' => __('The game or app, and where the original is (its official page or Google Play link)', 'y4apk')]); ?>
						<?php $field(__('Links on Y4APK to remove', 'y4apk'), 'urls', ['_textarea' => true, 'rows' => '5', 'maxlength' => '8000', 'required' => true, 'placeholder' => "https://y4apk.com/…\nhttps://y4apk.com/…", 'spellcheck' => 'false'], __('One per line: the pages or files. Up to 100.', 'y4apk')); ?>
						<h3 class="y4h-step"><span>3</span><?php esc_html_e('Statements and signature', 'y4apk'); ?></h3>
						<div class="y4h-checks" data-f="good">
							<label><input type="checkbox" name="good" value="1" required> <span><?php esc_html_e('I believe in good faith that the use of the material is not allowed by the copyright owner, its agent or the law.', 'y4apk'); ?></span></label>
							<label><input type="checkbox" name="true" value="1" required> <span><?php esc_html_e('The information in this notice is accurate.', 'y4apk'); ?></span></label>
							<label><input type="checkbox" name="allowed" value="1" required> <span><?php esc_html_e('Under penalty of perjury, I am the owner, or allowed to act for the owner, of the right that is said to be infringed.', 'y4apk'); ?></span></label>
						</div>
						<?php $field(__('Signature', 'y4apk'), 'signature', ['type' => 'text', 'maxlength' => '80', 'required' => true, 'autocomplete' => 'off'], __('Type your full name.', 'y4apk')); ?>
						<?php $hidden('copyright'); ?>
						<div data-y4h-ts><?php if ($members) { Y4APK_Members::turnstile_widget(); } ?></div>
						<?php $send_button(__('Send notice', 'y4apk')); $fine(); ?>
					</form>
					<div class="y4h-done" data-y4h-done hidden><span class="y4h-done-ic"><?php echo $svg('check', 30); ?></span><h2><?php esc_html_e('Notice received', 'y4apk'); ?></h2>
						<p><?php esc_html_e('We review it, usually within a few working days, and answer by email.', 'y4apk'); ?></p><p class="y4h-ref"><?php esc_html_e('Your notice’s number:', 'y4apk'); ?> <b data-y4h-ref></b></p></div>
				</section>
				<aside class="y4h-side">
					<div class="y4h-card y4h-tips">
						<h3><?php esc_html_e('Before you send', 'y4apk'); ?></h3>
						<ul>
							<li><?php echo $fill(__('<b>Only the owner, or someone allowed to act for them,</b> can send a notice.', 'y4apk')); ?></li>
							<li><?php echo $fill(__('<b>One notice can list many links.</b> Add each page or file you want removed.', 'y4apk')); ?></li>
							<li><?php echo $fill(__('<b>The uploader may get a copy,</b> without your email address.', 'y4apk')); ?></li>
							<li><?php echo $fill(__('<b>False claims can have legal consequences.</b> Not sure? Get advice first.', 'y4apk')); ?></li>
						</ul>
					</div>
					<div class="y4h-card y4h-more">
						<h3><?php esc_html_e('More', 'y4apk'); ?></h3>
						<a href="<?php echo esc_url(Y4APK_Help::url('dmca')); ?>"><?php echo $svg('scale', 18); ?><?php esc_html_e('Read our DMCA Policy', 'y4apk'); ?></a>
						<a href="<?php echo esc_url(add_query_arg('topic', 'copy', Y4APK_Help::url('contact'))); ?>"><?php echo $svg('mail', 18); ?><?php esc_html_e('Send a counter-notice', 'y4apk'); ?></a>
					</div>
				</aside>
			</div>

		<?php else : // feedback ?>
			<div class="y4h-narrow">
				<section class="y4h-card">
					<form class="y4h-form" data-y4h-form novalidate>
						<fieldset class="y4h-rate" data-f="rating">
							<legend><?php esc_html_e('How is Y4APK for you?', 'y4apk'); ?></legend>
							<div class="y4h-faces">
								<?php foreach ([1 => ['😞', __('Very bad', 'y4apk')], 2 => ['🙁', __('Bad', 'y4apk')], 3 => ['😐', __('Okay', 'y4apk')], 4 => ['🙂', __('Good', 'y4apk')], 5 => ['😍', __('Great', 'y4apk')]] as $n => $f) : ?>
									<label class="y4h-face"><input type="radio" name="rating" value="<?php echo (int) $n; ?>" required><span><i aria-hidden="true"><?php echo esc_html($f[0]); ?></i><small><?php echo esc_html($f[1]); ?></small></span></label>
								<?php endforeach; ?>
							</div>
						</fieldset>
						<div class="y4h-field" data-f="message"><label for="y4h-message"><?php esc_html_e('What do you like, or what should we do better?', 'y4apk'); ?> <span class="y4h-counter" data-count="2000">0 / 2,000</span></label>
							<textarea id="y4h-message" name="message" rows="5" maxlength="2000" placeholder="<?php esc_attr_e('A page, a feature, something that did not work…', 'y4apk'); ?>"></textarea></div>
						<div data-y4h-guest><?php $field(__('Email', 'y4apk'), 'email', ['type' => 'email', 'maxlength' => '100', 'autocomplete' => 'email'], __('Only if you would like an answer.', 'y4apk'), true); ?></div>
						<label class="y4h-from" data-y4h-from hidden><input type="checkbox" name="with_page" value="1" checked> <span><?php esc_html_e('Include the page I came from:', 'y4apk'); ?> <b data-y4h-from-url></b></span></label>
						<?php $hidden('feedback'); ?>
						<?php $send_button(__('Send feedback', 'y4apk')); $fine(); ?>
					</form>
					<div class="y4h-done" data-y4h-done hidden><span class="y4h-done-ic"><?php echo $svg('check', 30); ?></span><h2><?php esc_html_e('Thanks for your feedback!', 'y4apk'); ?></h2>
						<p><?php esc_html_e('It helps us decide what to make better next.', 'y4apk'); ?></p>
						<p><a class="y4h-btn y4h-btn-ghost" href="<?php echo esc_url(home_url('/')); ?>"><?php esc_html_e('Back to Y4APK', 'y4apk'); ?></a></p></div>
				</section>
				<p class="y4h-below"><?php echo $fill(__('Need an answer about a problem, your account or a copyright notice? Use the <a href="{contact}">Contact page</a>.', 'y4apk')); ?></p>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php if ($legal) : ?>
<script id="y4apk-pwa-help-toc" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var toc = document.querySelector('.y4h-toc-in'), links = toc ? [].slice.call(toc.querySelectorAll('a[href^="#"]')) : [];
	if (!toc) { return; }
	// Phones: the list folds up (it opens with a tap), and closes again after a pick
	var phone = window.matchMedia('(max-width: 61.9375rem)');
	if (phone.matches) { toc.open = false; }
	links.forEach(function (a) { a.addEventListener('click', function () { if (phone.matches) { toc.open = false; } }); });
	// The section being read, marked in the list
	if (!('IntersectionObserver' in window)) { return; }
	var on = function (id) { links.forEach(function (a) { a.classList.toggle('is-here', a.getAttribute('href') === '#' + id); }); };
	var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { on(e.target.id); } }); }, {rootMargin: '-20% 0px -70% 0px'});
	[].forEach.call(document.querySelectorAll('.y4h-sec[id]'), function (s) { io.observe(s); });
})();
</script>
<?php else : ?>
<script id="y4apk-pwa-help" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var d = document, form = d.querySelector('[data-y4h-form]');
	if (!form || !window.fetch || !window.FormData) { return; }
	var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
	var T = <?php echo wp_json_encode([
		'wait'    => __('Sending…', 'y4apk'),
		'error'   => __('Something went wrong. Try again in a minute.', 'y4apk'),
		'offline' => __('The connection dropped. Try again.', 'y4apk'),
		'status'  => Y4APK_Help::statuses(),
		'none'    => __('No messages yet.', 'y4apk'),
		'answer'  => __('Our answer:', 'y4apk'),
	]); ?>;
	var esc = function (s) { var x = d.createElement('div'); x.textContent = s == null ? '' : String(s); return x.innerHTML; };
	var q = function (s, root) { return (root || form).querySelector(s); };
	// Signed in (the header's cookie): their name instead of the name and email fields
	var me = null, m = d.cookie.match(/(?:^|;\s*)y4apk_me=([^;]+)/);
	if (m) { try { me = JSON.parse(decodeURIComponent(m[1])); } catch (e) { me = null; } }
	if (me && typeof me.l === 'string' && /^[a-z0-9_]{3,20}$/.test(me.l)) {
		[].forEach.call(d.querySelectorAll('[data-y4h-guest]'), function (g) { g.hidden = true; [].forEach.call(g.querySelectorAll('[required]'), function (r) { r.required = false; }); });
		var ts = q('[data-y4h-ts]'); if (ts) { ts.innerHTML = ''; }
		[].forEach.call(d.querySelectorAll('[data-y4h-as]'), function (as) {
			as.hidden = false; q('[data-y4h-name]', as).textContent = (me.n || me.l) + ' · @' + me.l;
			var av = q('[data-y4h-av]', as), h = (+me.h || 0) % 360;
			if (typeof me.a === 'string' && /^https:\/\/[^\s"'<>]+$/.test(me.a)) { var img = d.createElement('img'); img.src = me.a; img.alt = ''; av.appendChild(img); }
			else { av.textContent = Array.from(String(me.n || me.l))[0].toUpperCase(); av.style.background = 'linear-gradient(135deg,hsl(' + h + ',70%,52%),hsl(' + ((h + 40) % 360) + ',65%,38%))'; }
		});
		var name = q('[name="name"]'); if (name && !name.value && form.querySelector('[name="kind"]').value === 'copyright') { name.value = me.n || ''; }
	} else {
		var tsb = q('.cf-turnstile');
		if (tsb && !window.turnstile) { var s = d.createElement('script'); s.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js'; s.async = true; s.defer = true; d.head.appendChild(s); }
	}
	// The page this came from (Feedback); the topic from ?topic= (Contact)
	var ref = '';
	try { var r = new URL(d.referrer); if (r.host === location.host && r.pathname !== location.pathname) { ref = r.href; } } catch (e) {}
	var from = q('[data-y4h-from]');
	if (from && ref) { from.hidden = false; q('[data-y4h-from-url]').textContent = ref.replace(/^https?:\/\/[^\/]+/, '') || '/'; }
	var tq = (location.search.match(/[?&]topic=([a-z]+)/) || [])[1];
	if (tq) { var tr = q('[name="topic"][value="' + tq + '"]'); if (tr) { tr.checked = true; } }
	var topic = function () {
		var c = q('[name="topic"]:checked'), box = q('[data-y4h-copy]');
		if (box) { box.hidden = !c || c.value !== 'copy'; }
	};
	form.addEventListener('change', function (e) { if (e.target.name === 'topic') { topic(); } });
	topic();
	// Characters left
	[].forEach.call(form.querySelectorAll('[data-count]'), function (c) {
		var ta = c.closest('.y4h-field').querySelector('textarea'), max = +c.getAttribute('data-count');
		var up = function () { c.textContent = ta.value.length.toLocaleString() + ' / ' + max.toLocaleString(); c.classList.toggle('is-near', ta.value.length > max * .9); };
		ta.addEventListener('input', up); up();
	});
	// A page cache may have kept this page: a fresh time field when it is old
	var t = q('[name="y4t"]');
	if (t) {
		var body = new FormData(); body.append('action', 'y4apk_fresh');
		fetch(AJAX, {method: 'POST', body: body, credentials: 'same-origin', cache: 'no-store'}).then(function (r) { return r.json(); })
			.then(function (j) { if (j && j.t && !(j.now - parseInt(t.value, 10) < 3600)) { t.value = j.t; } }).catch(function () {});
	}
	var err = q('.y4h-err'), btn = q('.y4h-btn[type="submit"]');
	var show = function (msg, field) {
		err.textContent = msg; err.hidden = false;
		[].forEach.call(form.querySelectorAll('.is-bad'), function (x) { x.classList.remove('is-bad'); });
		var f = field ? form.querySelector('[data-f="' + field + '"]') : null;
		if (f) { f.classList.add('is-bad'); var i = f.querySelector('input,textarea'); if (i && i.focus) { i.focus(); } }
	};
	form.addEventListener('submit', function (e) {
		e.preventDefault();
		if (btn.disabled) { return; }
		var fd = new FormData(form);
		fd.append('action', 'y4apk_help_send');
		if (form.querySelector('[name="kind"]').value === 'feedback') { fd.set('page', fd.get('with_page') && ref ? ref : ''); } else { fd.set('page', ref); }
		btn.disabled = true; var label = btn.querySelector('span').textContent; btn.querySelector('span').textContent = T.wait;
		fetch(AJAX, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (j) {
			if (j && j.success) {
				form.hidden = true;
				var done = d.querySelector('[data-y4h-done]'); done.hidden = false;
				var rf = done.querySelector('[data-y4h-ref]'); if (rf) { rf.textContent = j.data.ref; }
				done.scrollIntoView({block: 'center', behavior: 'smooth'});
				return;
			}
			show(j && j.data && j.data.message ? j.data.message : T.error, j && j.data ? j.data.field : '');
			if (window.turnstile && q('.cf-turnstile')) { try { window.turnstile.reset(); } catch (x) {} }
		}).catch(function () { show(T.offline); }).then(function () { btn.disabled = false; btn.querySelector('span').textContent = label; });
	});
	// Your requests (Contact, signed in)
	var mine = d.querySelector('[data-y4h-mine-list]');
	if (mine && me) {
		var b2 = new FormData(); b2.append('action', 'y4apk_help_mine');
		fetch(AJAX, {method: 'POST', body: b2, credentials: 'same-origin', cache: 'no-store'}).then(function (r) { return r.json(); }).then(function (j) {
			var items = j && j.success ? j.data.items : [];
			d.querySelector('[data-y4h-mine-guest]').hidden = true; mine.hidden = false;
			mine.innerHTML = items.length ? items.map(function (x) {
				return '<li><div><b>' + esc(x.topic) + '</b><span class="y4h-st y4h-st-' + esc(x.status) + '">' + esc(T.status[x.status] || x.status) + '</span></div><small>' + esc(x.ref) + ' · ' + esc(x.when) + '</small>'
					+ (x.reply ? '<p><b>' + esc(T.answer) + '</b> ' + esc(x.reply) + '</p>' : '') + '</li>';
			}).join('') : '<li class="y4h-muted">' + esc(T.none) + '</li>';
		}).catch(function () {});
	}
})();
</script>
<?php endif; ?>
<?php get_template_part('template/footer'); get_footer(); ?>
