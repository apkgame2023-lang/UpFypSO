<?php
/**
 * Y4APK emails (2.40): one designed template for every email the theme sends,
 * with a plain-text copy, and sending through the site's own mailbox.
 *
 * Why Gmail said "via srv1636.main-hosting.eu": PHP's mail() hands the message
 * to the web server, which sends it as itself and does not sign it for
 * y4apk.com. Sent through a mailbox of the domain over SMTP (Hostinger Email:
 * smtp.hostinger.com), the mail server signs it with the domain's DKIM key and
 * the envelope sender is the mailbox, so the mail shows "from y4apk.com" with
 * nothing after it. An SMTP plugin (FluentSMTP, WP Mail SMTP) does the same; when
 * one is active, the theme leaves the sending to it and only designs the emails.
 *
 * Settings live in Y4APK ▸ Members ▸ Email; the password can instead be put in
 * wp-config.php as Y4APK_SMTP_PASS (out of the database). In the database it is
 * kept encrypted with a key made from the site's salts.
 */

defined('ABSPATH') || exit;

class Y4APK_Mail {
	/** @var string|null the plain-text copy of the message being sent */
	private static $alt = null;
	/** @var string the last sending error, for the test email */
	public static $error = '';

	public static function init() {
		add_action('phpmailer_init', [__CLASS__, 'phpmailer'], 20);
		add_action('wp_mail_failed', [__CLASS__, 'failed']);
		add_action('admin_post_y4apk_mail_preview', [__CLASS__, 'preview']);
	}

	/* ---------------------------------------------------------------- *
	 * Sending
	 * ---------------------------------------------------------------- */

	/**
	 * An email from the site. $m: title, preheader (the line inboxes show after the
	 * subject), hi (a name), lines (paragraphs), card ([label, text]), quote (a
	 * highlighted note), button ([label, url]), note (small text under it), why
	 * (why the reader gets this email).
	 */
	public static function send($to, $subject, $m) {
		$s = Y4APK_Members::settings();
		$from = self::from_address();
		$headers = ['From: ' . self::header_name($s['from_name']) . ' <' . $from . '>', 'Content-Type: text/html; charset=UTF-8'];
		if (!empty($s['reply_to']) && is_email($s['reply_to'])) {
			$headers[] = 'Reply-To: ' . $s['reply_to'];
		}
		self::$alt = self::text($m);
		self::$error = '';
		$ok = wp_mail($to, $subject, self::html($subject, $m), $headers);
		self::$alt = null;
		return $ok;
	}

	/** The address mail comes from: the SMTP mailbox when there is one, else noreply@ the site. */
	public static function from_address() {
		$c = self::smtp();
		if ($c && is_email($c['user'])) {
			return $c['user'];
		}
		return 'noreply@' . preg_replace('~^www\.~', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
	}

	private static function header_name($name) {
		$name = trim(str_replace(['"', "\r", "\n"], '', wp_specialchars_decode((string) $name, ENT_QUOTES)));
		return '"' . ($name !== '' ? $name : 'Y4APK') . '"';
	}

	/** The theme's SMTP settings when they are on and complete, else null. */
	public static function smtp() {
		$s = Y4APK_Members::settings();
		if (empty($s['smtp_on']) || $s['smtp_host'] === '' || $s['smtp_user'] === '') {
			return null;
		}
		$pass = defined('Y4APK_SMTP_PASS') && Y4APK_SMTP_PASS ? (string) Y4APK_SMTP_PASS : self::reveal($s['smtp_pass']);
		return $pass === '' ? null : ['host' => $s['smtp_host'], 'port' => (int) $s['smtp_port'], 'secure' => $s['smtp_secure'], 'user' => $s['smtp_user'], 'pass' => $pass];
	}

	/** An SMTP plugin that sends the site's mail (then the theme does not). */
	public static function plugin() {
		foreach (['fluent-smtp/fluent-smtp.php' => 'FluentSMTP', 'wp-mail-smtp/wp_mail_smtp.php' => 'WP Mail SMTP', 'post-smtp/postman-smtp.php' => 'Post SMTP', 'easy-wp-smtp/easy-wp-smtp.php' => 'Easy WP SMTP'] as $file => $name) {
			if (in_array($file, (array) get_option('active_plugins', []), true)) {
				return $name;
			}
		}
		return '';
	}

	public static function phpmailer($phpmailer) {
		$host = preg_replace('~^www\.~', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
		$phpmailer->XMailer = ' ';      // no "PHPMailer 6.x (github…)" header
		$phpmailer->Hostname = $host;    // Message-ID: <…@y4apk.com>, not the server's name
		if (self::$alt !== null) {
			$phpmailer->AltBody = self::$alt;
		}
		$c = self::smtp();
		if (!$c || self::plugin() !== '' || $phpmailer->Mailer === 'smtp') {
			return; // a plugin, or nothing set up: as before
		}
		$phpmailer->isSMTP();
		$phpmailer->Host = $c['host'];
		$phpmailer->Port = $c['port'];
		$phpmailer->SMTPAuth = true;
		$phpmailer->Username = $c['user'];
		$phpmailer->Password = $c['pass'];
		$phpmailer->SMTPSecure = $c['secure'] === 'tls' ? 'tls' : 'ssl';
		$phpmailer->Timeout = 15;
		$phpmailer->getSMTPInstance()->Timelimit = 20; // a slow or silent mail server must not hold a sign-up page for PHPMailer's 300 seconds
		$phpmailer->Sender = $c['user']; // the envelope sender ("mailed-by"): the mailbox, not the server
		if (strcasecmp($phpmailer->From, $c['user']) !== 0) {
			$phpmailer->From = $c['user']; // mail servers refuse a From that is not the mailbox
		}
	}

	public static function failed($error) {
		self::$error = is_wp_error($error) ? $error->get_error_message() : '';
	}

	/* ---------------------------------------------------------------- *
	 * The password in the database
	 * ---------------------------------------------------------------- */

	private static function key() {
		return hash('sha256', wp_salt('auth') . 'y4apk-smtp', true);
	}

	public static function hide($plain) {
		if ($plain === '' || !function_exists('openssl_encrypt')) {
			return $plain;
		}
		$iv = random_bytes(16);
		return 'enc:' . base64_encode($iv . openssl_encrypt($plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv));
	}

	public static function reveal($stored) {
		$stored = (string) $stored;
		if (strpos($stored, 'enc:') !== 0 || !function_exists('openssl_decrypt')) {
			return $stored;
		}
		$raw = base64_decode(substr($stored, 4), true);
		if ($raw === false || strlen($raw) < 17) {
			return '';
		}
		$out = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
		return $out === false ? '' : $out;
	}

	/* ---------------------------------------------------------------- *
	 * The email itself
	 * ---------------------------------------------------------------- */

	public static function html($subject, $m) {
		$m = wp_parse_args($m, ['title' => $subject, 'preheader' => '', 'hi' => '', 'lines' => [], 'card' => null, 'quote' => '', 'button' => null, 'note' => '', 'why' => '']);
		$s = Y4APK_Members::settings();
		$site = $s['from_name'];
		$home = home_url('/');
		$icon = get_template_directory_uri() . '/assets/img/pwa/icon-192.png';
		$ink = '#172021';
		$text = '#4a5559';
		$mute = '#8a9599';
		$blue = '#29b1d8';
		$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
		$e = function ($t) { return esc_html($t); };

		ob_start();
		?><!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title><?php echo $e($subject); ?></title>
<style>
	@media (max-width: 600px) { .y4-card { border-radius: 0 !important; } .y4-pad { padding-left: 24px !important; padding-right: 24px !important; } .y4-btn a { display: block !important; } }
	a { color: <?php echo $blue; ?>; }
</style>
</head>
<body style="margin:0;padding:0;background:#eef3f6;-webkit-text-size-adjust:100%;">
<?php if ($m['preheader'] !== '') : ?><div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:#eef3f6;opacity:0;"><?php echo $e($m['preheader']); ?><?php echo str_repeat('&#847;&zwnj;&nbsp;', 40); ?></div><?php endif; ?>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef3f6;">
	<tr><td align="center" style="padding:32px 12px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;">
			<tr><td style="padding:0 8px 20px;">
				<a href="<?php echo esc_url($home); ?>" style="text-decoration:none;">
					<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
						<td style="padding-right:10px;"><img src="<?php echo esc_url($icon); ?>" width="36" height="36" alt="" style="display:block;border:0;border-radius:10px;"></td>
						<td style="font-family:<?php echo $font; ?>;font-size:22px;font-weight:800;letter-spacing:-.5px;color:<?php echo $ink; ?>;">Y4<span style="color:<?php echo $blue; ?>;">Apk</span></td>
					</tr></table>
				</a>
			</td></tr>
			<tr><td class="y4-card" style="background:#ffffff;border-radius:20px;overflow:hidden;box-shadow:0 12px 32px -16px rgba(16,40,60,.25);">
				<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
					<tr><td style="height:6px;line-height:6px;font-size:0;background:<?php echo $blue; ?>;background-image:linear-gradient(90deg,#1a8fc0,<?php echo $blue; ?>,#7fe0ff);">&nbsp;</td></tr>
					<tr><td class="y4-pad" style="padding:36px 40px 8px;font-family:<?php echo $font; ?>;">
						<h1 style="margin:0 0 16px;font-size:24px;line-height:1.3;font-weight:800;color:<?php echo $ink; ?>;"><?php echo $e($m['title']); ?></h1>
						<?php if ($m['hi'] !== '') : ?><p style="margin:0 0 12px;font-size:15px;line-height:1.65;color:<?php echo $text; ?>;"><?php echo $e(sprintf(__('Hi %s,', 'y4apk'), $m['hi'])); ?></p><?php endif; ?>
						<?php foreach ((array) $m['lines'] as $line) : ?><p style="margin:0 0 12px;font-size:15px;line-height:1.65;color:<?php echo $text; ?>;"><?php echo $e($line); ?></p><?php endforeach; ?>
						<?php if ($m['card']) : ?>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 12px;"><tr><td style="padding:16px 18px;border-radius:14px;background:#f4f8fa;border:1px solid #e3ecef;">
							<p style="margin:0 0 4px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:<?php echo $mute; ?>;"><?php echo $e($m['card'][0]); ?></p>
							<p style="margin:0;font-size:16px;line-height:1.4;font-weight:700;color:<?php echo $ink; ?>;"><?php echo $e($m['card'][1]); ?></p>
						</td></tr></table>
						<?php endif; ?>
						<?php if ($m['quote'] !== '') : ?>
						<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 12px;"><tr><td style="padding:14px 18px;border-radius:14px;background:#fff6e5;border-left:4px solid #f0a020;font-size:15px;line-height:1.6;color:#6b4a00;"><?php echo nl2br($e($m['quote'])); ?></td></tr></table>
						<?php endif; ?>
						<?php if ($m['button']) : ?>
						<table role="presentation" cellpadding="0" cellspacing="0" border="0" class="y4-btn" style="margin:24px 0 8px;"><tr>
							<td align="center" bgcolor="<?php echo $blue; ?>" style="border-radius:12px;background:<?php echo $blue; ?>;">
								<a href="<?php echo esc_url($m['button'][1]); ?>" target="_blank" style="display:inline-block;padding:15px 30px;font-family:<?php echo $font; ?>;font-size:16px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:12px;"><?php echo $e($m['button'][0]); ?></a>
							</td>
						</tr></table>
						<?php endif; ?>
						<?php if ($m['note'] !== '') : ?><p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:<?php echo $mute; ?>;"><?php echo $e($m['note']); ?></p><?php endif; ?>
					</td></tr>
					<?php if ($m['button']) : ?>
					<tr><td class="y4-pad" style="padding:20px 40px 32px;font-family:<?php echo $font; ?>;">
						<p style="margin:0;padding-top:18px;border-top:1px solid #edf1f3;font-size:12px;line-height:1.6;color:<?php echo $mute; ?>;"><?php echo $e(__('Button not working? Copy this link into your browser:', 'y4apk')); ?><br><a href="<?php echo esc_url($m['button'][1]); ?>" style="color:<?php echo $blue; ?>;word-break:break-all;"><?php echo $e($m['button'][1]); ?></a></p>
					</td></tr>
					<?php else : ?>
					<tr><td style="height:24px;line-height:24px;font-size:0;">&nbsp;</td></tr>
					<?php endif; ?>
				</table>
			</td></tr>
			<tr><td align="center" style="padding:24px 16px 8px;font-family:<?php echo $font; ?>;font-size:12px;line-height:1.7;color:<?php echo $mute; ?>;">
				<?php if ($m['why'] !== '') : ?><?php echo $e($m['why']); ?><br><?php endif; ?>
				<a href="<?php echo esc_url($home); ?>" style="color:<?php echo $mute; ?>;text-decoration:underline;"><?php echo $e(preg_replace('~^https?://~', '', untrailingslashit($home))); ?></a>
				<?php if (class_exists('Y4APK_Roblox') && Y4APK_Roblox::hub_url()) : ?> · <a href="<?php echo esc_url(Y4APK_Roblox::hub_url()); ?>" style="color:<?php echo $mute; ?>;text-decoration:underline;"><?php echo $e(__('Roblox scripts', 'y4apk')); ?></a><?php endif; ?>
				<br>© <?php echo esc_html(wp_date('Y')); ?> <?php echo $e($site); ?>
			</td></tr>
		</table>
	</td></tr>
</table>
</body>
</html><?php
		return (string) ob_get_clean();
	}

	/** The same email as plain text, for mail apps that do not show HTML. */
	public static function text($m) {
		$m = wp_parse_args($m, ['title' => '', 'hi' => '', 'lines' => [], 'card' => null, 'quote' => '', 'button' => null, 'note' => '', 'why' => '']);
		$out = [];
		if ($m['hi'] !== '') {
			$out[] = sprintf(__('Hi %s,', 'y4apk'), $m['hi']);
		}
		foreach ((array) $m['lines'] as $line) {
			$out[] = $line;
		}
		if ($m['card']) {
			$out[] = $m['card'][0] . ': ' . $m['card'][1];
		}
		if ($m['quote'] !== '') {
			$out[] = $m['quote'];
		}
		if ($m['button']) {
			$out[] = $m['button'][0] . ":\n" . $m['button'][1];
		}
		if ($m['note'] !== '') {
			$out[] = $m['note'];
		}
		$out[] = '— ' . Y4APK_Members::settings()['from_name'] . "\n" . home_url('/');
		if ($m['why'] !== '') {
			$out[] = $m['why'];
		}
		return implode("\n\n", $out);
	}

	/* ---------------------------------------------------------------- *
	 * Previews (Members ▸ Email)
	 * ---------------------------------------------------------------- */

	/** The emails the theme sends, with made-up details: [key => [subject, message]]. */
	public static function samples() {
		$s = Y4APK_Members::settings();
		$u = 'dez';
		return [
			'verify'  => [sprintf(__('Confirm your email for %s', 'y4apk'), $s['from_name']), Y4APK_Members::message('verify', ['name' => $u, 'link' => Y4APK_Members::url('verify', ['u' => 0, 't' => 'example'])])],
			'reset'   => [__('Choose a new password', 'y4apk'), Y4APK_Members::message('reset', ['name' => $u, 'link' => Y4APK_Members::url('reset', ['key' => 'example', 'login' => $u])])],
			'live'    => [__('Your script is live', 'y4apk'), Y4APK_Members::message('live', ['name' => $u, 'title' => 'Blox Fruits Script Y4APK Hub (No Key)', 'link' => home_url('/')])],
			'changes' => [__('Your script needs a change', 'y4apk'), Y4APK_Members::message('changes', ['name' => $u, 'title' => 'Blox Fruits Script Y4APK Hub (No Key)', 'why' => __('The link does not work. Use the raw GitHub link.', 'y4apk'), 'link' => Y4APK_Members::url('edit/1')])],
			'waiting' => [__('A script is waiting for review', 'y4apk'), Y4APK_Members::message('waiting', ['name' => $u, 'title' => 'Blox Fruits Script Y4APK Hub (No Key)'])],
		];
	}

	public static function preview() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$all = self::samples();
		$k = isset($_GET['email']) ? sanitize_key(wp_unslash($_GET['email'])) : 'verify';
		$k = isset($all[$k]) ? $k : 'verify';
		nocache_headers();
		header('Content-Type: text/html; charset=utf-8');
		echo self::html($all[$k][0], $all[$k][1]); // built and escaped in html()
		exit;
	}
}

Y4APK_Mail::init();
