<?php
/**
 * /account/messages/ (2.67): the member's conversations and one open. Included
 * by template/account.php ($c, $M, $messages). Y4APK_DM::route() filled
 * $c['dm']; assets/js/messages.js draws and keeps it up to date.
 */
defined('ABSPATH') || exit;

$me = $c['user'];
$data = [
	'me'    => ['id' => (int) $me->ID, 'login' => $me->user_login],
	'api'   => esc_url_raw(rest_url('y4apk/v1/dm/')),
	'nonce' => wp_create_nonce('wp_rest'),
	'page'  => Y4APK_DM::url(),
	'inbox' => $c['dm']['inbox'],
	'open'  => $c['dm']['open'],
	'on'    => $c['dm']['on'],
	'lang'  => str_replace('_', '-', get_locale()),
	'T'     => [
		'title'     => __('Messages', 'y4apk'),
		'empty'     => __('No messages yet', 'y4apk'),
		'emptyWhy'  => __('Open a member’s profile and press Message to start a conversation.', 'y4apk'),
		'pick'      => __('Pick a conversation', 'y4apk'),
		'pickWhy'   => __('Or open a member’s profile and press Message.', 'y4apk'),
		'write'     => __('Write a message…', 'y4apk'),
		'send'      => __('Send', 'y4apk'),
		'hello'     => __('Say hello to %s', 'y4apk'),
		'helloWhy'  => __('Be kind. Never share passwords or pay anyone you do not know.', 'y4apk'),
		'older'     => __('Earlier messages', 'y4apk'),
		'today'     => __('Today', 'y4apk'),
		'yesterday' => __('Yesterday', 'y4apk'),
		'now'       => __('now', 'y4apk'),
		'seen'      => __('Seen', 'y4apk'),
		'sent'      => __('Sent', 'y4apk'),
		'sending'   => __('Sending…', 'y4apk'),
		'failed'    => __('Not sent. Tap to try again.', 'y4apk'),
		'unsent'    => __('Message unsent', 'y4apk'),
		'you'       => __('You: ', 'y4apk'),
		'back'      => __('Back', 'y4apk'),
		'more'      => __('More', 'y4apk'),
		'profile'   => __('View profile', 'y4apk'),
		'block'     => __('Block', 'y4apk'),
		'unblock'   => __('Unblock', 'y4apk'),
		'leave'     => __('Delete conversation', 'y4apk'),
		'leaveQ'    => __('Delete this conversation from your list? The other member keeps theirs. It comes back if they write again.', 'y4apk'),
		'blockQ'    => __('Block %s? Neither of you can write to the other until you unblock.', 'y4apk'),
		'unsend'    => __('Unsend', 'y4apk'),
		'unsendQ'   => __('Unsend this message? It goes for both of you.', 'y4apk'),
		'report'    => __('Report', 'y4apk'),
		'reportT'   => __('Report this message', 'y4apk'),
		'reportWhy' => __('The site’s team reads only the messages members report.', 'y4apk'),
		'spam'      => __('Spam', 'y4apk'),
		'scam'      => __('Scam or phishing', 'y4apk'),
		'abuse'     => __('Abuse or harassment', 'y4apk'),
		'other'     => __('Something else', 'y4apk'),
		'alsoBlock' => __('Also block this member', 'y4apk'),
		'cancel'    => __('Cancel', 'y4apk'),
		'reported'  => __('Thanks. The site’s team will look at it.', 'y4apk'),
		'error'     => __('Something went wrong. Try again.', 'y4apk'),
		'chars'     => __('%d characters left', 'y4apk'),
	],
];
?>
<div class="dm<?php echo $c['dm']['open'] ? ' is-chat' : ''; ?>" data-dm>
	<aside class="dm-side">
		<div class="dm-side-h"><h1><?php esc_html_e('Messages', 'y4apk'); ?></h1></div>
		<div class="dm-list" data-dm-list role="list"></div>
	</aside>
	<section class="dm-main" data-dm-main aria-live="polite"></section>
</div>
<script type="application/json" id="y4-dm-data"><?php echo wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?></script>
