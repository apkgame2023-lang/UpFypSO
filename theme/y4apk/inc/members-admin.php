<?php
/**
 * Members, the site owner's side: Y4APK ▸ Review (the scripts members sent,
 * to approve or send back), Y4APK ▸ Members (registration, Turnstile, limits,
 * the privacy checks), and the "Report" button on script posts.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Y4APK_Members_Admin {

	const REPORTS = '_y4apk_reports'; // [ip hash => [time, why]]
	const HOLD    = '_y4apk_hold';    // a member's script put back in review by reports

	public static function init() {
		add_action('admin_menu', [__CLASS__, 'menu'], 20);
		add_action('admin_post_y4apk_members_save', [__CLASS__, 'save_settings']);
		add_action('admin_post_y4apk_members_names', [__CLASS__, 'fix_names']);
		add_action('admin_post_y4apk_members_checks', [__CLASS__, 'run_checks']);
		add_action('admin_post_y4apk_drop_image', [__CLASS__, 'drop_image']);
		add_action('admin_post_y4apk_members_testmail', [__CLASS__, 'test_mail']);
		foreach (['approve', 'reject', 'ban', 'unban', 'dismiss', 'rereview'] as $a) {
			add_action('admin_post_y4apk_review_' . $a, [__CLASS__, 'review_' . $a]);
		}
		add_action('transition_post_status', function () { delete_transient('y4apk_review_counts'); }); // the badge on the menu follows what is waiting
		add_action('wp_ajax_y4apk_report', [__CLASS__, 'report']);
		add_action('wp_ajax_nopriv_y4apk_report', [__CLASS__, 'report']);
	}

	/* ---------------------------------------------------------------- *
	 * Menu
	 * ---------------------------------------------------------------- */

	/** Member scripts waiting, and published ones with reports: [waiting, reported]. */
	public static function counts() {
		$c = get_transient('y4apk_review_counts');
		if (!is_array($c)) {
			global $wpdb;
			$waiting = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = 'pending' AND p.post_type = 'post'", Y4APK_Members::MEMBER_POST));
			$reported = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s WHERE p.post_status = 'publish' AND p.post_type = 'post'", self::REPORTS));
			$c = [$waiting, $reported];
			set_transient('y4apk_review_counts', $c, 60);
		}
		return $c;
	}

	public static function menu() {
		list($waiting, $reported) = self::counts();
		$n = $waiting + $reported;
		$bubble = $n ? ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . (int) $n . '</span></span>' : '';
		add_submenu_page(Y4APK_MENU, __('Review', 'y4apk'), __('Review', 'y4apk') . $bubble, 'edit_others_posts', 'y4apk-review', [__CLASS__, 'review_page']);
		add_submenu_page(Y4APK_MENU, __('Members', 'y4apk'), __('Members', 'y4apk'), 'manage_options', 'y4apk-members', [__CLASS__, 'settings_page']);
	}

	private static function back($page, $args = []) {
		delete_transient('y4apk_review_counts');
		wp_safe_redirect(add_query_arg(array_merge(['page' => $page], $args), admin_url('admin.php')));
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * Review
	 * ---------------------------------------------------------------- */

	private static function allowed_hosts() {
		$list = preg_split('/\s+/', strtolower((string) Y4APK_Members::settings()['hosts']), -1, PREG_SPLIT_NO_EMPTY);
		return is_array($list) ? $list : [];
	}

	private static function host_ok($host) {
		foreach (self::allowed_hosts() as $h) {
			if ($host === $h || substr($host, -strlen($h) - 1) === '.' . $h) {
				return true;
			}
		}
		return false;
	}

	private static function guard($do, $id = 0) {
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_review_' . $do . '_' . $id);
	}

	public static function review_approve() {
		$id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		self::guard('approve', $id);
		$post = get_post($id);
		if (!$post || !get_post_meta($id, Y4APK_Members::MEMBER_POST, true)) {
			self::back('y4apk-review', ['msg' => 'gone']);
		}
		$term = isset($_POST['game']) ? absint($_POST['game']) : 0;
		$new = isset($_POST['game_new']) ? trim(sanitize_text_field(wp_unslash($_POST['game_new']))) : '';
		if ($new !== '') {
			$term = Y4APK_Roblox::find_or_create($new);
			if (is_wp_error($term)) {
				self::back('y4apk-review', ['msg' => 'nogame']);
			}
		}
		if (!$term || !get_term($term, Y4APK_Roblox::TAX) || is_wp_error(get_term($term, Y4APK_Roblox::TAX))) {
			self::back('y4apk-review', ['msg' => 'nogame']);
		}
		wp_set_object_terms($id, [(int) $term], Y4APK_Roblox::TAX);
		if (!get_post_thumbnail_id($id) && ($att = (int) get_term_meta($term, Y4APK_Roblox::M_ICON, true))) {
			set_post_thumbnail($id, $att);
		}
		update_post_meta($id, 'wp_title_GP', wp_slash(Y4APK_Roblox::clean_name(get_term($term, Y4APK_Roblox::TAX)->name, false) . ' Script - [' . (Y4APK_Roblox::hub_name($id) ?: __('Script', 'y4apk')) . ']'));
		delete_post_meta($id, Y4APK_Members::REJECT);
		delete_post_meta($id, Y4APK_Members::WANTED);
		delete_post_meta($id, self::HOLD);
		wp_update_post(['ID' => $id, 'post_status' => 'publish']); // Y4APK_Members::on_publish() tells the member
		self::back('y4apk-review', ['msg' => 'approved']);
	}

	public static function review_reject() {
		$id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		self::guard('reject', $id);
		$post = get_post($id);
		$why = isset($_POST['reason']) ? trim(mb_substr(sanitize_textarea_field(wp_unslash($_POST['reason'])), 0, 300)) : '';
		if (!$post || !get_post_meta($id, Y4APK_Members::MEMBER_POST, true)) {
			self::back('y4apk-review', ['msg' => 'gone']);
		}
		if ($why === '') {
			self::back('y4apk-review', ['msg' => 'noreason']);
		}
		update_post_meta($id, Y4APK_Members::REJECT, wp_slash($why));
		wp_update_post(['ID' => $id, 'post_status' => 'draft']);
		$user = get_userdata($post->post_author);
		if ($user && Y4APK_Members::is_member($user)) {
			Y4APK_Members::mail($user->user_email, __('Your script needs a change', 'y4apk'),
				Y4APK_Members::message('changes', ['name' => $user->user_login, 'title' => wp_strip_all_tags(get_the_title($id)), 'why' => $why, 'link' => Y4APK_Members::url('edit/' . $id)]));
		}
		self::back('y4apk-review', ['msg' => 'rejected']);
	}

	public static function review_ban() {
		$uid = isset($_POST['user']) ? absint($_POST['user']) : 0;
		self::guard('ban', $uid);
		$user = get_userdata($uid);
		if ($user && Y4APK_Members::is_member($user)) {
			update_user_meta($uid, Y4APK_Members::BANNED, 1);
			Y4APK_Social::drop_images($uid); // a ban takes their pictures down too
			WP_Session_Tokens::get_instance($uid)->destroy_all();
			foreach (get_posts(['author' => $uid, 'post_type' => 'post', 'post_status' => ['pending', 'draft'], 'numberposts' => -1, 'fields' => 'ids']) as $pid) {
				wp_trash_post($pid);
			}
			foreach (get_posts(['author' => $uid, 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids']) as $pid) {
				wp_update_post(['ID' => $pid, 'post_status' => 'pending']); // what they published is checked again
			}
		}
		self::back('y4apk-review', ['msg' => 'banned']);
	}

	public static function review_unban() {
		$uid = isset($_POST['user']) ? absint($_POST['user']) : 0;
		self::guard('unban', $uid);
		delete_user_meta($uid, Y4APK_Members::BANNED);
		self::back(isset($_POST['back']) && $_POST['back'] === 'dashboard' ? Y4APK_MENU : 'y4apk-review', ['msg' => 'unbanned']);
	}

	public static function review_dismiss() {
		$id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		self::guard('dismiss', $id);
		delete_post_meta($id, self::REPORTS);
		delete_post_meta($id, self::HOLD);
		self::back('y4apk-review', ['msg' => 'dismissed']);
	}

	public static function review_rereview() {
		$id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		self::guard('rereview', $id);
		wp_update_post(['ID' => $id, 'post_status' => 'pending']);
		delete_post_meta($id, self::REPORTS);
		self::back('y4apk-review', ['msg' => 'rereview']);
	}

	private static function form($do, $id, $fields, $label, $class = 'button', $confirm = '') {
		echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="y4rv-form"' . ($confirm ? ' onsubmit="return confirm(\'' . esc_js($confirm) . '\')"' : '') . '>';
		echo '<input type="hidden" name="action" value="y4apk_review_' . esc_attr($do) . '">';
		wp_nonce_field('y4apk_review_' . $do . '_' . $id);
		echo $fields;
		echo '<button class="' . esc_attr($class) . '">' . esc_html($label) . '</button></form>';
	}

	public static function review_page() {
		if (!current_user_can('edit_others_posts')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$msgs = ['approved' => __('Approved: it is live, and the member was told.', 'y4apk'), 'rejected' => __('Sent back to the member with your note.', 'y4apk'), 'banned' => __('Account closed; what was waiting is removed and what they published is back in review.', 'y4apk'),
			'unbanned' => __('Account allowed again.', 'y4apk'), 'dismissed' => __('Reports cleared.', 'y4apk'), 'rereview' => __('Back in review.', 'y4apk'), 'gone' => __('That script is no longer here.', 'y4apk'),
			'nogame' => __('Choose the game (or type a new one) before approving.', 'y4apk'), 'noreason' => __('Write what the member should change.', 'y4apk')];
		$msg = isset($_GET['msg'], $msgs[$_GET['msg']]) ? $msgs[$_GET['msg']] : '';
		$bad = isset($_GET['msg']) && in_array($_GET['msg'], ['nogame', 'noreason', 'gone'], true);
		$pending = get_posts(['post_type' => 'post', 'post_status' => 'pending', 'meta_key' => Y4APK_Members::MEMBER_POST, 'numberposts' => 50, 'orderby' => 'date', 'order' => 'ASC']);
		$reported = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'meta_key' => self::REPORTS, 'numberposts' => 50]);
		$games = get_terms(['taxonomy' => Y4APK_Roblox::TAX, 'hide_empty' => false, 'orderby' => 'name']);
		$games = is_array($games) ? $games : [];
		self::styles();
		?>
		<div class="wrap y4rv">
			<h1><?php esc_html_e('Review', 'y4apk'); ?></h1>
			<?php if ($msg) : ?><div class="notice <?php echo $bad ? 'notice-error' : 'notice-success'; ?> is-dismissible"><p><?php echo esc_html($msg); ?></p></div><?php endif; ?>
			<p class="description"><?php esc_html_e('Scripts members sent. Nothing shows on the site until you approve it. Check where the code loads from before you do: the code itself can change later at that address.', 'y4apk'); ?></p>

			<h2><?php echo esc_html(sprintf(_n('%s script waiting', '%s scripts waiting', count($pending), 'y4apk'), number_format_i18n(count($pending)))); ?></h2>
			<?php if (!$pending) : ?><div class="y4rv-empty"><?php esc_html_e('Nothing is waiting. 🎉', 'y4apk'); ?></div><?php endif; ?>
			<?php foreach ($pending as $p) {
				self::card($p, $games);
			} ?>

			<?php if ($reported) : ?>
				<h2><?php echo esc_html(sprintf(_n('%s published script was reported', '%s published scripts were reported', count($reported), 'y4apk'), number_format_i18n(count($reported)))); ?></h2>
				<?php foreach ($reported as $p) :
					$rep = get_post_meta($p->ID, self::REPORTS, true);
					$rep = is_array($rep) ? $rep : [];
					$why = array_count_values(array_map(function ($r) { return isset($r[1]) ? $r[1] : 'other'; }, $rep)); ?>
					<div class="y4rv-card">
						<div class="y4rv-head"><div><strong><a href="<?php echo esc_url(get_permalink($p)); ?>" target="_blank"><?php echo esc_html(get_the_title($p)); ?></a></strong>
							<span class="y4rv-sub"><?php echo esc_html(sprintf(_n('%s report', '%s reports', count($rep), 'y4apk'), count($rep))); ?>:
								<?php echo esc_html(implode(', ', array_map(function ($k, $n) { return $k . ' ×' . $n; }, array_keys($why), $why))); ?></span></div>
							<div class="y4rv-actions">
								<?php self::form('dismiss', $p->ID, '<input type="hidden" name="post" value="' . esc_attr($p->ID) . '">', __('Dismiss reports', 'y4apk')); ?>
								<?php self::form('rereview', $p->ID, '<input type="hidden" name="post" value="' . esc_attr($p->ID) . '">', __('Hide and review again', 'y4apk'), 'button button-primary'); ?>
								<a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php esc_html_e('Edit', 'y4apk'); ?></a>
							</div></div>
						<?php self::codes($p->ID); ?>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function codes($id) {
		$hosts = get_post_meta($id, Y4APK_Members::HOSTS, true);
		if (!is_array($hosts)) {
			$hosts = [];
			foreach (Y4APK_Scripts::get($id) as $row) {
				if (preg_match_all('~https?://([a-z0-9.-]+)~i', $row['code'], $m)) {
					$hosts = array_merge($hosts, array_map('strtolower', $m[1]));
				}
			}
			$hosts = array_unique($hosts);
		}
		foreach (Y4APK_Scripts::get($id) as $row) {
			echo '<div class="y4rv-code"><span>' . esc_html($row['label'] !== '' ? $row['label'] : __('Code', 'y4apk')) . '</span><pre>' . esc_html($row['code']) . '</pre></div>';
		}
		if ($hosts) {
			echo '<p class="y4rv-hosts">' . esc_html__('Loads from:', 'y4apk') . ' ';
			foreach ($hosts as $h) {
				$ok = self::host_ok($h);
				echo '<span class="y4rv-host ' . ($ok ? 'is-ok' : 'is-new') . '" title="' . esc_attr($ok ? __('On your list of trusted addresses', 'y4apk') : __('Not on your list: open it and read the code before you approve', 'y4apk')) . '">' . esc_html($h) . '</span>';
			}
			echo '</p>';
		} else {
			echo '<p class="y4rv-hosts"><span class="y4rv-host is-new">' . esc_html__('No address in the code', 'y4apk') . '</span></p>';
		}
	}

	private static function card($p, $games) {
		$user = get_userdata($p->post_author);
		$wanted = (string) get_post_meta($p->ID, Y4APK_Members::WANTED, true);
		$have = Y4APK_Roblox::game_of($p->ID);
		$guess = $have ? $have->term_id : 0;
		if (!$guess && $wanted !== '') {
			$t = get_term_by('name', $wanted, Y4APK_Roblox::TAX) ?: get_term_by('slug', sanitize_title($wanted), Y4APK_Roblox::TAX);
			$guess = $t ? $t->term_id : 0;
		}
		$counts = $user ? count_user_posts($user->ID, 'post', true) : 0;
		$desc = '';
		if (preg_match('~<p>(.*?)</p>~s', $p->post_content, $m)) {
			$desc = html_entity_decode(wp_strip_all_tags($m[1]), ENT_QUOTES, 'UTF-8');
		}
		?>
		<div class="y4rv-card">
			<div class="y4rv-head">
				<div><strong><?php echo esc_html(get_the_title($p)); ?></strong>
					<span class="y4rv-sub"><?php if ($user) {
						echo esc_html(sprintf(__('@%1$s · member for %2$s · %3$s live', 'y4apk'), $user->user_login, human_time_diff(strtotime($user->user_registered)), number_format_i18n($counts)));
					} ?> · <?php echo esc_html(sprintf(__('sent %s ago', 'y4apk'), human_time_diff(get_post_datetime($p)->getTimestamp()))); ?></span></div>
				<div class="y4rv-actions"><a class="button" href="<?php echo esc_url(get_edit_post_link($p->ID)); ?>"><?php esc_html_e('Edit', 'y4apk'); ?></a>
					<?php if ($user) {
						self::form('ban', $user->ID, '<input type="hidden" name="user" value="' . esc_attr($user->ID) . '">', __('Ban member', 'y4apk'), 'button y4rv-danger', __('Close this account and remove what is waiting?', 'y4apk'));
					} ?></div>
			</div>
			<?php if ($desc !== '') : ?><p class="y4rv-desc"><?php echo esc_html($desc); ?></p><?php endif; ?>
			<?php self::codes($p->ID); ?>
			<div class="y4rv-decide">
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4rv-approve">
					<input type="hidden" name="action" value="y4apk_review_approve"><input type="hidden" name="post" value="<?php echo esc_attr($p->ID); ?>">
					<?php wp_nonce_field('y4apk_review_approve_' . $p->ID); ?>
					<label><?php esc_html_e('Game', 'y4apk'); ?>
						<select name="game"><option value="0"><?php esc_html_e('Choose…', 'y4apk'); ?></option>
							<?php foreach ($games as $g) : ?><option value="<?php echo esc_attr($g->term_id); ?>" <?php selected($guess, $g->term_id); ?>><?php echo esc_html($g->name); ?></option><?php endforeach; ?></select></label>
					<label><?php echo esc_html($wanted !== '' && !$guess ? sprintf(__('or create “%s”', 'y4apk'), $wanted) : __('or a new game', 'y4apk')); ?>
						<input type="text" name="game_new" value="<?php echo esc_attr(!$guess ? $wanted : ''); ?>" placeholder="<?php esc_attr_e('Name or roblox.com link', 'y4apk'); ?>"></label>
					<button class="button button-primary"><?php esc_html_e('Approve and publish', 'y4apk'); ?></button>
				</form>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="y4rv-reject">
					<input type="hidden" name="action" value="y4apk_review_reject"><input type="hidden" name="post" value="<?php echo esc_attr($p->ID); ?>">
					<?php wp_nonce_field('y4apk_review_reject_' . $p->ID); ?>
					<input type="text" name="reason" maxlength="300" placeholder="<?php esc_attr_e('What should the member change? (they get this by email)', 'y4apk'); ?>">
					<button class="button"><?php esc_html_e('Send back', 'y4apk'); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	private static function styles() {
		static $done = false;
		if ($done) {
			return;
		}
		$done = true;
		echo '<style>.y4rv h2{margin:1.5rem 0 .75rem}.y4rv-empty{padding:2rem;text-align:center;background:#fff;border:1px dashed #c3c4c7;border-radius:12px;color:#646970}'
			. '.y4rv-card{margin:0 0 1rem;padding:1rem 1.25rem;background:#fff;border:1px solid #dcdcde;border-radius:12px;max-width:980px}'
			. '.y4rv-head{display:flex;flex-wrap:wrap;gap:.5rem 1rem;align-items:flex-start;justify-content:space-between}.y4rv-head strong{font-size:15px}'
			. '.y4rv-sub{display:block;margin-top:2px;font-size:12px;color:#646970}.y4rv-actions{display:flex;gap:.5rem;align-items:center}.y4rv-form{display:inline}'
			. '.y4rv-danger{color:#b32d2e!important;border-color:#b32d2e!important}.y4rv-desc{margin:.5rem 0;color:#3c434a}'
			. '.y4rv-code{margin:.5rem 0}.y4rv-code span{font-size:11px;font-weight:600;color:#646970}.y4rv-code pre{margin:2px 0 0;padding:10px 12px;background:#1d2327;color:#f0f0f1;border-radius:8px;white-space:pre-wrap;overflow-wrap:anywhere;font-size:12px}'
			. '.y4rv-hosts{margin:.25rem 0 .75rem;font-size:12px;color:#646970}.y4rv-host{display:inline-block;margin:0 4px 4px 0;padding:2px 8px;border-radius:99px;font-weight:600}'
			. '.y4rv-host.is-ok{background:#e4f7ea;color:#14602a}.y4rv-host.is-new{background:#fff4de;color:#7a4d00}'
			. '.y4rv-decide{display:flex;flex-wrap:wrap;gap:.75rem 2rem;align-items:flex-end;padding-top:.75rem;border-top:1px solid #f0f0f1}'
			. '.y4rv-approve{display:flex;flex-wrap:wrap;gap:.5rem;align-items:flex-end}.y4rv-approve label{display:flex;flex-direction:column;font-size:12px;gap:2px}'
			. '.y4rv-reject{display:flex;gap:.5rem;flex:1 1 320px}.y4rv-reject input{flex:1}</style>';
	}

	/* ---------------------------------------------------------------- *
	 * Reports from visitors
	 * ---------------------------------------------------------------- */

	/** The small "Report" link under a script's code. */
	public static function report_ui($post_id) {
		?>
		<div class="y4-report" data-post="<?php echo esc_attr($post_id); ?>">
			<button type="button" class="y4-report-open"><?php esc_html_e('Report this script', 'y4apk'); ?></button>
			<form class="y4-report-form" hidden>
				<select name="why" aria-label="<?php esc_attr_e('What is wrong?', 'y4apk'); ?>">
					<option value="broken"><?php esc_html_e('It does not work', 'y4apk'); ?></option>
					<option value="harmful"><?php esc_html_e('It is harmful or steals accounts', 'y4apk'); ?></option>
					<option value="spam"><?php esc_html_e('Spam or wrong game', 'y4apk'); ?></option>
					<option value="other"><?php esc_html_e('Something else', 'y4apk'); ?></option>
				</select>
				<input type="text" name="website" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">
				<button type="submit"><?php esc_html_e('Send', 'y4apk'); ?></button>
				<span class="y4-report-msg" role="status"></span>
			</form>
		</div>
		<script>
		(function () {
			if (window.y4apkReport) { return; }
			window.y4apkReport = 1;
			var url = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, thanks = <?php echo wp_json_encode(__('Thanks, we will look at it.', 'y4apk')); ?>, sorry = <?php echo wp_json_encode(__('Could not send. Try again later.', 'y4apk')); ?>;
			document.addEventListener('click', function (e) {
				var o = e.target.closest && e.target.closest('.y4-report-open');
				if (o) { o.parentNode.querySelector('form').hidden = false; o.hidden = true; }
			});
			document.addEventListener('submit', function (e) {
				var f = e.target.closest && e.target.closest('.y4-report-form');
				if (!f) { return; }
				e.preventDefault();
				var d = new FormData(f), msg = f.querySelector('.y4-report-msg');
				d.append('action', 'y4apk_report');
				d.append('post', f.parentNode.getAttribute('data-post'));
				fetch(url, { method: 'POST', body: d, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
					msg.textContent = j && j.success ? thanks : sorry;
					if (j && j.success) { f.querySelector('button').disabled = true; }
				}).catch(function () { msg.textContent = sorry; });
			});
		})();
		</script>
		<?php
	}

	public static function report() {
		$id = isset($_POST['post']) ? absint($_POST['post']) : 0;
		$why = isset($_POST['why']) ? sanitize_key(wp_unslash($_POST['why'])) : 'other';
		$why = in_array($why, ['broken', 'harmful', 'spam', 'other'], true) ? $why : 'other';
		$post = $id ? get_post($id) : null;
		if (!empty($_POST['website']) || !$post || $post->post_type !== 'post' || $post->post_status !== 'publish' || !Y4APK_Roblox::is_roblox_post($id)) {
			wp_send_json_error(['message' => 'no'], 400);
		}
		$ip = Y4APK_Members::ip_hash();
		if (!Y4APK_Members::hit('report_' . $ip, 8, HOUR_IN_SECONDS)) {
			wp_send_json_error(['message' => 'slow'], 429);
		}
		$rep = get_post_meta($id, self::REPORTS, true);
		$rep = is_array($rep) ? $rep : [];
		if (!isset($rep[$ip]) && count($rep) < 60) {
			$rep[$ip] = [time(), $why];
			update_post_meta($id, self::REPORTS, $rep);
			delete_transient('y4apk_review_counts');
			$limit = Y4APK_Members::settings()['report_hide'];
			$author = get_userdata($post->post_author);
			if ($limit && count($rep) >= $limit && $author && Y4APK_Members::is_member($author)) {
				update_post_meta($id, self::HOLD, 1);
				wp_update_post(['ID' => $id, 'post_status' => 'pending']); // out of sight until the owner has looked
				Y4APK_Members::mail(get_option('admin_email'), __('A script was hidden after reports', 'y4apk'),
					Y4APK_Members::message('reported', ['title' => wp_strip_all_tags(get_the_title($id)), 'count' => count($rep)]));
			}
		}
		wp_send_json_success();
	}

	/* ---------------------------------------------------------------- *
	 * Y4APK ▸ Members
	 * ---------------------------------------------------------------- */

	public static function save_settings() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_members_save');
		$in = wp_unslash($_POST);
		$old = get_option(Y4APK_Members::OPTION, []);
		$old = is_array($old) ? $old : [];
		$hosts = [];
		foreach (preg_split('/\s+/', strtolower((string) (isset($in['hosts']) ? $in['hosts'] : '')), -1, PREG_SPLIT_NO_EMPTY) as $h) {
			$h = preg_replace('~^https?://~', '', $h);
			$h = preg_replace('~[/?#].*$~', '', $h);
			if (preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $h)) {
				$hosts[$h] = 1;
			}
		}
		$secret = isset($in['ts_secret']) ? trim((string) $in['ts_secret']) : '';
		update_option(Y4APK_Members::OPTION, array_merge($old, [
			'on'          => empty($in['on']) ? 0 : 1,
			'ts_site'     => preg_replace('/[^A-Za-z0-9_-]/', '', isset($in['ts_site']) ? (string) $in['ts_site'] : ''),
			'ts_secret'   => $secret !== '' ? preg_replace('/[^A-Za-z0-9_-]/', '', $secret) : (isset($old['ts_secret']) ? $old['ts_secret'] : ''),
			'per_day'     => max(1, min(50, (int) (isset($in['per_day']) ? $in['per_day'] : 5))),
			'max_pending' => max(1, min(200, (int) (isset($in['max_pending']) ? $in['max_pending'] : 15))),
			'report_hide' => max(0, min(50, (int) (isset($in['report_hide']) ? $in['report_hide'] : 5))),
			'notify'      => empty($in['notify']) ? 0 : 1,
			'from_name'   => trim(mb_substr(sanitize_text_field(isset($in['from_name']) ? (string) $in['from_name'] : 'Y4APK'), 0, 40)) ?: 'Y4APK',
			'hosts'       => implode("\n", array_keys($hosts)),
			'reply_to'    => is_email(isset($in['reply_to']) ? trim((string) $in['reply_to']) : '') ? sanitize_email($in['reply_to']) : '',
			'smtp_on'     => empty($in['smtp_on']) ? 0 : 1,
			'smtp_host'   => preg_replace('/[^a-z0-9.-]/i', '', isset($in['smtp_host']) ? (string) $in['smtp_host'] : '') ?: 'smtp.hostinger.com',
			'smtp_port'   => in_array((int) (isset($in['smtp_port']) ? $in['smtp_port'] : 465), [25, 465, 587, 2525], true) ? (int) $in['smtp_port'] : 465,
			'smtp_secure' => isset($in['smtp_secure']) && $in['smtp_secure'] === 'tls' ? 'tls' : 'ssl',
			'smtp_user'   => is_email(isset($in['smtp_user']) ? trim((string) $in['smtp_user']) : '') ? sanitize_email($in['smtp_user']) : '',
			'smtp_pass'   => isset($in['smtp_pass']) && (string) $in['smtp_pass'] !== '' ? Y4APK_Mail::hide((string) $in['smtp_pass']) : (isset($old['smtp_pass']) ? $old['smtp_pass'] : ''),
		]), false);
		if (!empty($in['clear_secret'])) {
			$o = get_option(Y4APK_Members::OPTION);
			$o['ts_secret'] = '';
			update_option(Y4APK_Members::OPTION, $o, false);
		}
		self::back('y4apk-members', ['saved' => 1]);
	}

	public static function fix_names() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_members_names');
		$n = Y4APK_Members::neutralise_names();
		delete_transient('y4apk_members_checks');
		self::back('y4apk-members', ['names' => $n]);
	}

	/** Takes down a member's picture or banner (from R2 too). */
	public static function drop_image() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_drop_image');
		$kind = isset($_GET['kind']) && $_GET['kind'] === 'banner' ? 'banner' : 'pic';
		Y4APK_Social::drop_image(isset($_GET['user']) ? absint($_GET['user']) : 0, $kind);
		self::back('y4apk-members', ['dropped' => 1]);
	}

	public static function test_mail() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_members_testmail');
		$c = Y4APK_Mail::smtp();
		$how = Y4APK_Mail::plugin() !== '' ? Y4APK_Mail::plugin() : ($c ? sprintf(__('your mailbox %1$s through %2$s', 'y4apk'), $c['user'], $c['host']) : __('this web server (PHP mail), not your mailbox', 'y4apk'));
		$ok = Y4APK_Members::mail(get_option('admin_email'), __('Test email', 'y4apk'), ['title' => __('Your emails work', 'y4apk'), 'preheader' => __('A test from Y4APK ▸ Members.', 'y4apk'),
			'lines' => [__('If you can read this, members will get their confirmation emails.', 'y4apk')], 'card' => [__('Sent through', 'y4apk'), $how],
			'note' => __('In Gmail, open ⋮ ▸ Show original: SPF, DKIM and DMARC should all say PASS for your domain.', 'y4apk'), 'why' => __('You sent this test from Y4APK ▸ Members.', 'y4apk')]);
		if (!$ok) {
			set_transient('y4apk_mail_error', Y4APK_Mail::$error, 5 * MINUTE_IN_SECONDS);
		}
		self::back('y4apk-members', ['mail' => $ok ? 'sent' : 'failed']);
	}

	private static function get_headers_of($url) {
		$r = wp_remote_get($url, ['timeout' => 10, 'redirection' => 0, 'sslverify' => false, 'headers' => ['Cache-Control' => 'no-cache']]);
		return is_wp_error($r) ? null : $r;
	}

	/**
	 * Is /account/ served fresh? A form kept in a page cache stops working after
	 * 6 hours (its hidden time field gets too old), so open the sign-in page twice,
	 * like a browser, and see whether the second one is a stored copy.
	 */
	private static function cache_check() {
		$label = __('Member pages kept out of the page cache', 'y4apk');
		$url = home_url('/account/login/');
		$args = ['timeout' => 10, 'redirection' => 0, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'];
		$first = wp_remote_get($url, $args);
		sleep(2);
		$start = time();
		$r = wp_remote_get($url, $args);
		if (is_wp_error($first) || is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
			return [$label, null, __('could not check', 'y4apk')];
		}
		$hit = false;
		$seen = []; // the headers that name the cache, to know which one to set
		foreach (wp_remote_retrieve_headers($r) as $name => $value) {
			$value = is_array($value) ? implode(', ', $value) : (string) $value;
			if (preg_match('/cache|cdn|^age$|^server$|^via$|tenweb|litespeed|hcdn/i', $name) && !preg_match('/^(cache-control|cdn-cache-control|surrogate-control)$/i', $name)) {
				$seen[] = strtolower($name) . ': ' . mb_substr($value, 0, 40);
			}
			if (stripos($name, 'cache') !== false && preg_match('/\b(HIT|STALE)\b/i', $value)) {
				$hit = true;
			}
		}
		$old = preg_match('/name="y4t" value="(\d+)\./', (string) wp_remote_retrieve_body($r), $m) && (int) $m[1] < $start - 1;
		if (!$hit && !$old) {
			return [$label, true, __('every visitor gets a fresh sign-in page', 'y4apk')];
		}
		$what = $old ? sprintf(__('a copy made %s ago', 'y4apk'), human_time_diff((int) $m[1], $start)) : __('the cache says HIT', 'y4apk');
		if ($seen) {
			$what .= '; ' . implode('; ', array_slice($seen, 0, 6));
		}
		return [$label, false, sprintf(__('/account/login/ comes from a cache (%s). The forms renew their hidden fields, so signing up still works, but exclude /account/ and /u/ from that cache, clear it and check again', 'y4apk'), $what)];
	}

	/** Looks at the site the way a stranger does: [label, ok (true|false|null), what was found]. */
	public static function check_results() {
		$rows = [];
		$r = self::get_headers_of(home_url('/?author=1&y4=' . time()));
		$loc = $r ? (string) wp_remote_retrieve_header($r, 'location') : '';
		$rows[] = [__('Author address (?author=1)', 'y4apk'), $r ? !preg_match('~/author/~', $loc) : null, $r ? ($loc !== '' ? sprintf(__('goes to %s', 'y4apk'), preg_replace('~^https?://[^/]+~', '', $loc)) : __('shows nothing about authors', 'y4apk')) : __('could not check', 'y4apk')];
		$r = self::get_headers_of(rest_url('wp/v2/users'));
		$code = $r ? (int) wp_remote_retrieve_response_code($r) : 0;
		$rows[] = [__('Users list in the REST API', 'y4apk'), $r ? ($code >= 400) : null, $r ? sprintf(__('answers %d to a stranger', 'y4apk'), $code) : __('could not check', 'y4apk')];
		$r = self::get_headers_of(home_url('/?y4=' . time()));
		$html = $r ? (string) wp_remote_retrieve_body($r) : '';
		$found = [];
		if (preg_match_all(Y4APK_Members::GENERATOR, $html, $m)) {
			foreach ($m[0] as $tag) {
				$found[] = preg_match('/\bcontent\s*=\s*["\']([^"\']*)/i', $tag, $c) ? mb_substr($c[1], 0, 60) : trim($tag);
			}
		}
		$rows[] = [__('Versions in the page (generator tags)', 'y4apk'), $r ? !$found : null, $r ? ($found ? sprintf(__('the page still says “%s”: clear the page cache and check again', 'y4apk'), implode('”, “', $found)) : __('no generator tag', 'y4apk')) : __('could not check', 'y4apk')];
		$r = self::get_headers_of(home_url('/xmlrpc.php'));
		$code = $r ? (int) wp_remote_retrieve_response_code($r) : 0;
		$rows[] = [__('XML-RPC (a way to guess passwords)', 'y4apk'), $r ? ($code !== 200 || stripos((string) wp_remote_retrieve_body($r), 'XML-RPC server accepts') === false) : null, $r ? sprintf(__('answers %d', 'y4apk'), $code) : __('could not check', 'y4apk')];
		$rows[] = self::cache_check();
		$r = self::get_headers_of(home_url('/readme.html'));
		$code = $r ? (int) wp_remote_retrieve_response_code($r) : 0;
		$rows[] = [__('readme.html is public', 'y4apk'), $r ? $code >= 400 : null, $r ? ($code < 400 ? __('it names the WordPress version: delete readme.html in File Manager (WordPress puts it back with each update)', 'y4apk') : __('not reachable', 'y4apk')) : __('could not check', 'y4apk')];
		return $rows;
	}

	public static function run_checks() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		check_admin_referer('y4apk_members_checks');
		set_transient('y4apk_members_checks', ['time' => time(), 'rows' => self::check_results()], DAY_IN_SECONDS);
		self::back('y4apk-members', ['checked' => 1]);
	}

	public static function settings_page() {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to do that.', 'y4apk'), 403);
		}
		$s = Y4APK_Members::settings();
		$names = Y4APK_Members::public_names();
		$bad_names = array_filter($names, function ($r) { return !$r['ok']; });
		$checks = get_transient('y4apk_members_checks');
		$ts_const = (defined('Y4APK_TURNSTILE_SITE') && Y4APK_TURNSTILE_SITE) || (defined('Y4APK_TURNSTILE_SECRET') && Y4APK_TURNSTILE_SECRET);
		$total = count(get_users(['role' => Y4APK_Members::ROLE, 'fields' => 'ID']));
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Members', 'y4apk'); ?></h1>
			<?php if (!empty($_GET['saved'])) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e('Saved.', 'y4apk'); ?></p></div><?php endif; ?>
			<?php if (isset($_GET['names'])) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html(sprintf(_n('%s account’s public name changed.', '%s accounts’ public names changed.', absint($_GET['names']), 'y4apk'), absint($_GET['names']))); ?></p></div><?php endif; ?>
			<?php if (isset($_GET['mail'])) : $why = (string) get_transient('y4apk_mail_error'); ?><div class="notice <?php echo $_GET['mail'] === 'sent' ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html($_GET['mail'] === 'sent' ? sprintf(__('Test email sent to %s. If it does not arrive, look in Spam, and send it through your mailbox (below).', 'y4apk'), get_option('admin_email')) : __('The email could not be sent.', 'y4apk') . ($why !== '' ? ' ' . sprintf(__('The mail server said: %s', 'y4apk'), $why) : '') . ' ' . __('Check the mailbox address, its password, the server and the port.', 'y4apk')); ?></p></div><?php endif; ?>

			<p style="max-width:780px"><?php esc_html_e('Members share Roblox scripts from pages of your site (/account/), without seeing WordPress. Every script waits in Y4APK ▸ Review until you approve it. Turn registration on only when the checks below are green.', 'y4apk'); ?>
				<?php echo esc_html(sprintf(_n('%s member so far.', '%s members so far.', $total, 'y4apk'), number_format_i18n($total))); ?></p>

			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
				<input type="hidden" name="action" value="y4apk_members_save"><?php wp_nonce_field('y4apk_members_save'); ?>
				<table class="form-table" role="presentation"><tbody>
					<tr><th scope="row"><?php esc_html_e('Registration', 'y4apk'); ?></th>
						<td><label><input type="checkbox" name="on" value="1" <?php checked($s['on']); ?>> <strong><?php esc_html_e('Anyone can create an account and send scripts', 'y4apk'); ?></strong></label>
							<p class="description"><?php esc_html_e('Off: nobody new can sign up or send; members who already have an account can still sign in.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e('Bot protection', 'y4apk'); ?></th>
						<td><?php if (!Y4APK_Members::turnstile_on()) : ?><p class="notice notice-warning inline" style="margin:0 0 .75rem;padding:8px 12px"><?php esc_html_e('Cloudflare Turnstile is not set. Until it is, only a hidden trap and limits protect the forms: set it up before you open registration.', 'y4apk'); ?></p><?php endif; ?>
							<p><label><?php esc_html_e('Site key', 'y4apk'); ?><br><input type="text" class="regular-text code" name="ts_site" value="<?php echo esc_attr($s['ts_site']); ?>" <?php disabled($ts_const); ?> autocomplete="off"></label></p>
							<p><label><?php esc_html_e('Secret key', 'y4apk'); ?><br><input type="password" class="regular-text code" name="ts_secret" value="" placeholder="<?php echo esc_attr($s['ts_secret'] !== '' ? '•••••••••• ' . __('(saved: leave empty to keep)', 'y4apk') : ''); ?>" <?php disabled($ts_const); ?> autocomplete="new-password"></label>
								<?php if ($s['ts_secret'] !== '' && !$ts_const) : ?><label style="margin-left:1rem"><input type="checkbox" name="clear_secret" value="1"> <?php esc_html_e('Remove it', 'y4apk'); ?></label><?php endif; ?></p>
							<p class="description"><?php esc_html_e('Free at dash.cloudflare.com ▸ Turnstile ▸ Add widget (Managed, your domain). Or put Y4APK_TURNSTILE_SITE and Y4APK_TURNSTILE_SECRET in wp-config.php, out of the database.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e('Limits', 'y4apk'); ?></th>
						<td><p><label><input type="number" min="1" max="50" name="per_day" value="<?php echo esc_attr($s['per_day']); ?>" style="width:5rem"> <?php esc_html_e('scripts a member can send in a day', 'y4apk'); ?></label></p>
							<p><label><input type="number" min="1" max="200" name="max_pending" value="<?php echo esc_attr($s['max_pending']); ?>" style="width:5rem"> <?php esc_html_e('waiting for review at once, per member', 'y4apk'); ?></label></p>
							<p><label><input type="number" min="0" max="50" name="report_hide" value="<?php echo esc_attr($s['report_hide']); ?>" style="width:5rem"> <?php esc_html_e('different visitors’ reports that put a member’s script back in review (0: never)', 'y4apk'); ?></label></p></td></tr>
					<tr><th scope="row"><label for="y4m-hosts"><?php esc_html_e('Trusted addresses', 'y4apk'); ?></label></th>
						<td><textarea id="y4m-hosts" name="hosts" rows="6" class="large-text code" style="max-width:420px"><?php echo esc_textarea($s['hosts']); ?></textarea>
							<p class="description" style="max-width:640px"><?php esc_html_e('One per line. In Review, the addresses a code loads from are green when they are here and amber when they are not. It does not block anything: it tells you where to look first.', 'y4apk'); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e('Email', 'y4apk'); ?></th>
						<td><p><label><?php esc_html_e('Sender name', 'y4apk'); ?> <input type="text" name="from_name" value="<?php echo esc_attr($s['from_name']); ?>" maxlength="40" class="regular-text"></label></p>
							<p><label><?php esc_html_e('Replies go to (optional)', 'y4apk'); ?> <input type="email" name="reply_to" value="<?php echo esc_attr($s['reply_to']); ?>" class="regular-text" placeholder="support@<?php echo esc_attr(preg_replace('~^www\.~', '', (string) wp_parse_url(home_url(), PHP_URL_HOST))); ?>"></label></p>
							<p><label><input type="checkbox" name="notify" value="1" <?php checked($s['notify']); ?>> <?php echo esc_html(sprintf(__('Email %s when a script is waiting (at most one every 15 minutes)', 'y4apk'), get_option('admin_email'))); ?></label></p>
							<p class="description" style="max-width:720px"><?php esc_html_e('Every email has the site’s design and a plain-text copy. See them:', 'y4apk'); ?>
								<?php foreach (['verify' => __('Confirm email', 'y4apk'), 'reset' => __('New password', 'y4apk'), 'live' => __('Script live', 'y4apk'), 'changes' => __('Needs a change', 'y4apk'), 'waiting' => __('Waiting for review', 'y4apk')] as $k => $label) : ?>
								<a href="<?php echo esc_url(admin_url('admin-post.php?action=y4apk_mail_preview&email=' . $k)); ?>" target="_blank" rel="noopener"><?php echo esc_html($label); ?></a><?php echo $k !== 'waiting' ? ' · ' : ''; ?>
								<?php endforeach; ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e('Send through your mailbox', 'y4apk'); ?></th>
						<td>
							<?php $plugin = Y4APK_Mail::plugin(); $live = Y4APK_Mail::smtp(); ?>
							<?php if ($plugin !== '') : ?>
								<div class="notice notice-info inline"><p><?php echo esc_html(sprintf(__('%s sends the site’s mail, so these settings are not used. The emails still have the theme’s design.', 'y4apk'), $plugin)); ?></p></div>
							<?php elseif ($live) : ?>
								<div class="notice notice-success inline"><p><?php echo esc_html(sprintf(__('Mail goes out from %1$s through %2$s, signed for your domain.', 'y4apk'), $live['user'], $live['host'])); ?></p></div>
							<?php else : ?>
								<div class="notice notice-warning inline"><p><?php esc_html_e('Mail goes out from the web server, so Gmail shows “via srv….main-hosting.eu” and it lands in Spam more often. Send it through a mailbox of your domain instead.', 'y4apk'); ?></p></div>
							<?php endif; ?>
							<p><label><input type="checkbox" name="smtp_on" value="1" <?php checked($s['smtp_on']); ?>> <?php esc_html_e('Send the site’s email through this mailbox (SMTP)', 'y4apk'); ?></label></p>
							<p><label><?php esc_html_e('Mailbox', 'y4apk'); ?> <input type="email" name="smtp_user" value="<?php echo esc_attr($s['smtp_user']); ?>" class="regular-text" placeholder="noreply@<?php echo esc_attr(preg_replace('~^www\.~', '', (string) wp_parse_url(home_url(), PHP_URL_HOST))); ?>" autocomplete="off"></label></p>
							<p><label><?php esc_html_e('Its password', 'y4apk'); ?> <input type="password" name="smtp_pass" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr(defined('Y4APK_SMTP_PASS') && Y4APK_SMTP_PASS ? __('set in wp-config.php', 'y4apk') : ($s['smtp_pass'] !== '' ? __('saved: leave empty to keep', 'y4apk') : '')); ?>"></label></p>
							<p><label><?php esc_html_e('Server', 'y4apk'); ?> <input type="text" name="smtp_host" value="<?php echo esc_attr($s['smtp_host']); ?>" class="regular-text"></label>
								<label><?php esc_html_e('Port', 'y4apk'); ?> <select name="smtp_port"><?php foreach ([465, 587, 2525, 25] as $port) : ?><option value="<?php echo (int) $port; ?>" <?php selected((int) $s['smtp_port'], $port); ?>><?php echo (int) $port; ?></option><?php endforeach; ?></select></label>
								<label><select name="smtp_secure"><option value="ssl" <?php selected($s['smtp_secure'], 'ssl'); ?>>SSL</option><option value="tls" <?php selected($s['smtp_secure'], 'tls'); ?>>TLS</option></select></label></p>
							<p class="description" style="max-width:720px"><?php esc_html_e('Hostinger: make the mailbox in hPanel ▸ Emails (for example noreply@ your domain), then use server smtp.hostinger.com, port 465, SSL, the mailbox address and its password. Mail then comes from your domain and is signed with its DKIM key, so Gmail shows no “via”. The password is kept encrypted; or put define( \'Y4APK_SMTP_PASS\', \'…\' ); in wp-config.php instead.', 'y4apk'); ?></p>
						</td></tr>
				</tbody></table>
				<?php submit_button(__('Save', 'y4apk')); ?>
			</form>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:-.5rem 0 1.5rem">
				<input type="hidden" name="action" value="y4apk_members_testmail"><?php wp_nonce_field('y4apk_members_testmail'); ?>
				<button class="button"><?php esc_html_e('Send myself a test email', 'y4apk'); ?></button>
			</form>

			<?php
			global $wpdb;
			$pics = $wpdb->get_results($wpdb->prepare("SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s) ORDER BY umeta_id DESC LIMIT 24", Y4APK_Social::PIC, Y4APK_Social::BANNER));
			?>
			<h2><?php esc_html_e('Members’ pictures', 'y4apk'); ?></h2>
			<p class="description" style="max-width:720px"><?php esc_html_e('The newest profile pictures and banners members uploaded (kept in Cloudflare R2). Take down one that does not belong: it is removed from R2 too, and a ban removes all of a member’s.', 'y4apk'); ?></p>
			<?php $imagick = class_exists('Imagick') && Imagick::queryFormats('GIF'); $cap = Y4APK_Social::upload_max(); ?>
			<p class="description" style="max-width:720px">
				<?php echo esc_html(sprintf(__('Members may send pictures up to %1$s. They go from the browser straight to R2 (with the CORS rule in Y4APK ▸ App updates ▸ R2 settings, the same one your uploads use); this server then fetches each one, makes it the size it is shown at, keeps that on R2 and deletes what was sent. If a browser cannot reach R2, the picture comes through this server instead, which takes up to %2$s.', 'y4apk'), size_format($cap), size_format(wp_max_upload_size()))); ?>
				<?php echo esc_html($imagick ? __('Moving GIFs are shrunk to the size they are shown at, frame by frame (Imagick).', 'y4apk') : __('Imagick is not on this server, so moving GIFs are kept as they are sent: up to 1000 × 1000 (banners 2000 × 1000) and 10 MB (banners 20 MB). Turn on the imagick extension in hPanel ▸ Advanced ▸ PHP Configuration to take bigger ones.', 'y4apk')); ?>
			</p>
			<?php if (!empty($_GET['dropped'])) : ?><div class="notice notice-success inline"><p><?php esc_html_e('Picture removed.', 'y4apk'); ?></p></div><?php endif; ?>
			<?php if ($pics) : ?>
			<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px;max-width:980px;margin:12px 0 28px">
				<?php foreach ($pics as $row) : $v = maybe_unserialize($row->meta_value); $pu = get_userdata((int) $row->user_id); if (!$pu || !is_array($v) || empty($v['url'])) { continue; } $is_banner = $row->meta_key === Y4APK_Social::BANNER; ?>
				<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;overflow:hidden">
					<a href="<?php echo esc_url($v['url']); ?>" target="_blank" rel="noopener"><img src="<?php echo esc_url($v['url']); ?>" alt="" style="display:block;width:100%;aspect-ratio:<?php echo $is_banner ? '3/1' : '1/1'; ?>;object-fit:cover;background:#f0f0f1"></a>
					<div style="padding:8px 10px;font-size:12px;line-height:1.5">
						<a href="<?php echo esc_url(Y4APK_Members::profile_url($pu)); ?>" target="_blank" rel="noopener">@<?php echo esc_html($pu->user_login); ?></a> · <?php echo esc_html($is_banner ? __('banner', 'y4apk') : __('picture', 'y4apk')); ?><br>
						<span style="color:#646970"><?php echo esc_html(isset($v['at']) ? Y4APK_Roblox::ago((int) $v['at']) : ''); ?></span>
						<a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=y4apk_drop_image&user=' . (int) $pu->ID . '&kind=' . ($is_banner ? 'banner' : 'pic')), 'y4apk_drop_image')); ?>" style="float:right;color:#b32d2e" onclick="return confirm('<?php echo esc_js(__('Take this picture down?', 'y4apk')); ?>')"><?php esc_html_e('Take down', 'y4apk'); ?></a>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php else : ?><p><em><?php esc_html_e('No pictures yet.', 'y4apk'); ?></em></p><?php endif; ?>

			<h2><?php esc_html_e('Privacy: what a stranger can learn about your accounts', 'y4apk'); ?></h2>
			<p class="description" style="max-width:780px"><?php esc_html_e('WordPress shows the sign-in name of whoever wrote a post, in several places. The theme closes them. This looks at your site the way a stranger does.', 'y4apk'); ?></p>
			<table class="widefat striped" style="max-width:780px"><tbody>
				<tr><td style="width:2rem"><?php echo $bad_names ? '⚠️' : '✅'; ?></td><td><strong><?php esc_html_e('Public name of the team accounts', 'y4apk'); ?></strong><br><span class="description"><?php
					echo esc_html($bad_names ? sprintf(_n('%s account prints its sign-in name as its public name (in feeds, Rank Math, schema).', '%s accounts print their sign-in names as their public names (in feeds, Rank Math, schema).', count($bad_names), 'y4apk'), count($bad_names)) : __('Every team account shows the site’s name.', 'y4apk')); ?></span></td>
					<td style="text-align:right"><?php if ($bad_names) : ?><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="y4apk_members_names"><?php wp_nonce_field('y4apk_members_names'); ?><button class="button button-primary"><?php esc_html_e('Use the site’s name', 'y4apk'); ?></button></form><?php endif; ?></td></tr>
				<?php if ($checks) : foreach ($checks['rows'] as $row) : ?>
					<tr><td><?php echo $row[1] === null ? '❔' : ($row[1] ? '✅' : '⚠️'); ?></td><td colspan="2"><strong><?php echo esc_html($row[0]); ?></strong><br><span class="description"><?php echo esc_html($row[2]); ?></span></td></tr>
				<?php endforeach; endif; ?>
			</tbody></table>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:1rem">
				<input type="hidden" name="action" value="y4apk_members_checks"><?php wp_nonce_field('y4apk_members_checks'); ?>
				<button class="button"><?php echo esc_html($checks ? __('Check again', 'y4apk') : __('Check my site now', 'y4apk')); ?></button>
				<?php if ($checks) : ?><span class="description"><?php echo esc_html(sprintf(__('Last checked %s ago.', 'y4apk'), human_time_diff($checks['time']))); ?></span><?php endif; ?>
			</form>
			<p class="description" style="max-width:780px;margin-top:1rem"><?php esc_html_e('Not something a plugin can change: the name you sign in with. If it was visible before this update, anyone could have copied it. Make a new administrator with a name nobody can guess (Users ▸ Add New), sign in as it, and delete the old one, giving its posts to the new one. Turn on two-step sign-in for the administrators.', 'y4apk'); ?></p>
		</div>
		<?php
	}
}

Y4APK_Members_Admin::init();
