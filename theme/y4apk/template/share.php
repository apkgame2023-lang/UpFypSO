<?php
/**
 * /upload/game/, /upload/app/ and /account/edit/N/ for a game or app (2.44):
 * five steps, the file straight to R2. Included by template/account.php, with
 * its helpers ($c, $M, $v, $messages, $form_open, $si).
 */
defined('ABSPATH') || exit;

$sh = $c['share'];
$editing = (bool) $sh['post'];
$kind = $v('kind', $sh['kind']);
$me = $c['user'];
$play = (array) $v('play', []);
$update_of = (int) $v('update_of', 0);
$site = $update_of ? ['id' => $update_of, 'title' => wp_strip_all_tags(get_the_title($update_of)), 'name' => html_entity_decode((string) get_post_meta($update_of, 'wp_title_GP', true), ENT_QUOTES, 'UTF-8'),
	'icon' => (string) get_the_post_thumbnail_url($update_of, 'thumbnail'), 'version' => Y4APK_App_Updates::number(Y4APK_App_Updates::current_version($update_of)), 'url' => get_permalink($update_of)] : null;
$icon_id = (int) $v('icon', 0);
$mods = array_filter(array_map('trim', explode(',', (string) $v('mods', ''))));
$known = array_merge(Y4APK_Share::MODS, ['Original']);
$mod_more = implode(', ', array_diff($mods, $known));
$start = (int) $v('step', 0);
$start = $start ?: ($c['errors'] ? 5 : 1);
$files = array_values((array) $v('files', []));
$cfg = [
	'ajax'  => admin_url('admin-ajax.php'),
	'nonce' => wp_create_nonce('y4apk_share'),
	'cats'  => $sh['cats'],
	'popular' => $sh['popular'],
	'max'   => $sh['max'],
	'start' => $start,
	'files' => array_map(function ($f) { return ['key' => $f['key'], 'size' => (float) $f['size'], 'name' => $f['name'], 'label' => $f['label'], 'sizeText' => Y4APK_R2::size_text($f['size'])]; }, $files),
	'pick'  => $site ? ['type' => 'site'] + $site : ($play ? ['type' => 'play', 'package' => $v('package'), 'title' => $play['title'] ?? $v('name'), 'developer' => $play['developer'] ?? '', 'icon' => $play['icon'] ?? '', 'genre' => $play['genre'] ?? '', 'version' => $play['version'] ?? '', 'rating' => $play['rating'] ?? 0]
		: ($v('name') !== '' ? ['type' => 'manual', 'title' => $v('name'), 'icon' => $icon_id ? (string) wp_get_attachment_image_url($icon_id, 'thumbnail') : ''] : null)),
	'T'     => [
		'find'     => __('Find the game or app first: a Google Play link, or its name.', 'y4apk'),
		'icon'     => __('Add its icon: it is not on Google Play, so we have no picture of it.', 'y4apk'),
		'cat'      => __('Choose its category.', 'y4apk'),
		'version'  => __('Write the version of the file, like 1.2.3.', 'y4apk'),
		'file'     => __('Upload the file first.', 'y4apk'),
		'wait'     => __('Wait for the upload to finish.', 'y4apk'),
		'agree'    => __('Tick the box to say the file is safe and fine to share.', 'y4apk'),
		'looking'  => __('Looking on Google Play…', 'y4apk'),
		'onsite'   => __('On Y4APK', 'y4apk'),
		'onplay'   => __('On Google Play', 'y4apk'),
		'have'     => __('On Y4APK: add your version under it', 'y4apk'),
		'newpost'  => __('New on Y4APK', 'y4apk'),
		'nothing'  => __('Nothing found. Paste its Google Play link, or add it by hand below.', 'y4apk'),
		'busy'     => __('Google Play is busy; the site’s own results are below. Try again in a minute.', 'y4apk'),
		'newer'    => __('Your own post, listed on this game’s page with your name (the site’s post has v%s; it stays as it is).', 'y4apk'),
		'manual'   => __('Not on Google Play: added by hand', 'y4apk'),
		'types'    => __('Send an APK, XAPK, APKS, APKM, ZIP or OBB file.', 'y4apk'),
		'big'      => __('Files can be up to %s.', 'y4apk'),
		'starting' => __('Starting…', 'y4apk'),
		'left'     => __('%s left', 'y4apk'),
		'paused'   => __('Paused', 'y4apk'),
		'retry'    => __('Connection lost: trying again…', 'y4apk'),
		'joining'  => __('Checking the file…', 'y4apk'),
		'done'     => __('Uploaded', 'y4apk'),
		'pause'    => __('Pause', 'y4apk'),
		'resume'   => __('Resume', 'y4apk'),
		'failed'   => __('The upload stopped. Press Resume to carry on.', 'y4apk'),
		'cancel'   => __('Remove this file?', 'y4apk'),
		'leave'    => __('The file is still uploading. Leave anyway?', 'y4apk'),
		'resumed'  => __('Carrying on where it stopped', 'y4apk'),
		'same'     => __('Same category as the main post', 'y4apk'),
		'files'    => __('%d files', 'y4apk'),
		'none'     => __('none', 'y4apk'),
	],
];
$ic = function ($name, $s = 18) {
	$p = [
		'pad'    => '<path d="M7 6h10a5 5 0 0 1 4.9 6l-1 5a3 3 0 0 1-5.2 1.4L13.6 16h-3.2l-2.1 2.4A3 3 0 0 1 3.1 17l-1-5A5 5 0 0 1 7 6Zm1 3v2H6v2h2v2h2v-2h2v-2h-2V9H8Zm8.5 1a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3Z"/>',
		'app'    => '<path d="M4 4h7v7H4V4Zm9 0h7v7h-7V4ZM4 13h7v7H4v-7Zm12.5 0a3.5 3.5 0 1 1 0 7 3.5 3.5 0 0 1 0-7Z"/>',
		'search' => '<path d="M10.5 3a7.5 7.5 0 0 1 6 12l4.7 4.8-1.4 1.4-4.8-4.7A7.5 7.5 0 1 1 10.5 3Zm0 2a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11Z"/>',
		'check'  => '<path d="m9.5 16.2-4.2-4.2-1.4 1.4 5.6 5.6 11-11-1.4-1.4-9.6 9.6Z"/>',
		'arrow'  => '<path d="M13.2 5.3 19.9 12l-6.7 6.7-1.4-1.4 4.3-4.3H4v-2h12.1l-4.3-4.3 1.4-1.4Z"/>',
		'back'   => '<path d="M10.8 5.3 4.1 12l6.7 6.7 1.4-1.4L7.9 13H20v-2H7.9l4.3-4.3-1.4-1.4Z"/>',
		'cloud'  => '<path d="M19.4 10a7 7 0 0 0-13.3-1.9A5.5 5.5 0 0 0 6.5 19H19a4.5 4.5 0 0 0 .4-9ZM13 13v4h-2v-4H8l4-4 4 4h-3Z"/>',
		'image'  => '<path d="M19 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm0 16H5V5h14v14Zm-5.1-7.1-3 3.9-2.1-2.6L6 17h12l-4.1-5.1Z"/>',
		'shield' => '<path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Zm-1.5 14.2-3.7-3.7 1.4-1.4 2.3 2.3 5.3-5.3 1.4 1.4-6.7 6.7Z"/>',
		'bolt'   => '<path d="M13 2 4 14h7l-1 8 9-12h-7l1-8Z"/>',
		'star'   => '<path d="m12 2 3 6.6 7.2.7-5.4 4.8 1.6 7.1L12 17.5l-6.4 3.7 1.6-7.1L1.8 9.3 9 8.6 12 2Z"/>',
		'x'      => '<path d="M18.3 5.7 12 12l6.3 6.3-1.4 1.4L10.6 13.4 4.3 19.7l-1.4-1.4L9.2 12 2.9 5.7l1.4-1.4 6.3 6.3 6.3-6.3 1.4 1.4Z"/>',
		'plus'   => '<path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/>',
		'play'   => '<path d="M4.6 2.3c-.4.3-.6.8-.6 1.4v16.6c0 .6.2 1.1.6 1.4l9.3-9.7L4.6 2.3Zm10.8 8.2 2.9-3-12-6.8 9.1 9.8Zm0 3-9.1 9.8 12-6.8-2.9-3Zm4.5-4.1-2.6-1.5-3.2 3.3 3.2 3.3 2.6-1.5c1-.6 1-2.9 0-3.6Z"/>',
		'hand'   => '<path d="M3 17.3V21h3.8l11-11.1-3.7-3.7L3 17.3Zm17.7-10.2a1 1 0 0 0 0-1.4l-2.4-2.4a1 1 0 0 0-1.4 0l-1.8 1.8 3.7 3.8 1.9-1.8Z"/>',
	];
	return '<svg viewBox="0 0 24 24" width="' . (int) $s . '" height="' . (int) $s . '" fill="currentColor" aria-hidden="true">' . $p[$name] . '</svg>';
};
$steps = [1 => __('Find it', 'y4apk'), 2 => __('Category', 'y4apk'), 3 => __('Details', 'y4apk'), 4 => __('Upload the file', 'y4apk'), 5 => __('Review and send', 'y4apk')];
?>
<div class="acc-crumb"><a href="<?php echo esc_url($M::url()); ?>">← <?php esc_html_e('My uploads', 'y4apk'); ?></a></div>
<div class="acc-wiz shr" data-shr data-cfg="<?php echo esc_attr(wp_json_encode($cfg)); ?>">
	<div class="acc-wiz-head">
		<span class="acc-wiz-badge shr-badge" data-head-icon><?php echo $ic($kind === 'app' ? 'app' : 'pad', 24); ?></span>
		<div class="acc-wiz-head-t">
			<span class="acc-wiz-eyebrow"><?php esc_html_e('Share on Y4APK', 'y4apk'); ?></span>
			<h1 data-head-title><?php echo esc_html($editing ? __('Edit your upload', 'y4apk') : ($kind === 'app' ? __('Share an app', 'y4apk') : __('Share a game', 'y4apk'))); ?></h1>
			<p class="acc-wiz-meta">
				<span>APK · XAPK · APKS · APKM · ZIP · OBB</span>
				<span><?php echo $ic('bolt', 14); ?><?php echo esc_html(sprintf(__('Up to %s', 'y4apk'), size_format($sh['max']))); ?></span>
				<span><?php echo $ic('shield', 14); ?><?php esc_html_e('Checked before it goes live', 'y4apk'); ?></span>
			</p>
		</div>
		<?php if (!$editing) { $kinds_switch($kind); } ?>
	</div>
	<div class="acc-wiz-grid">
		<aside class="acc-card acc-wiz-steps shr-steps">
			<h2><?php esc_html_e('How to share it', 'y4apk'); ?></h2>
			<p><?php esc_html_e('Five steps, about a minute (plus the upload)', 'y4apk'); ?></p>
			<ol>
				<?php foreach ($steps as $n => $label) : ?>
				<li data-goto="<?php echo (int) $n; ?>"><span class="acc-wiz-num"><i><?php echo (int) $n; ?></i><?php echo $ic('check'); ?></span><span><b><?php echo esc_html($label); ?></b><small data-step-sub="<?php echo (int) $n; ?>"></small></span></li>
				<?php endforeach; ?>
			</ol>
			<div class="shr-perks">
				<p><?php echo $ic('bolt', 16); ?><?php esc_html_e('Straight to our cloud: fast, and it can stop and carry on.', 'y4apk'); ?></p>
				<p><?php echo $ic('shield', 16); ?><?php esc_html_e('Every file is checked by a person before it goes live.', 'y4apk'); ?></p>
			</div>
		</aside>
		<div class="acc-card acc-wiz-main">
			<?php if ($editing && (string) get_post_meta($sh['post']->ID, Y4APK_Members::REJECT, true) !== '') : ?><div class="acc-note acc-note-err"><b><?php esc_html_e('What to change:', 'y4apk'); ?></b> <?php echo esc_html(get_post_meta($sh['post']->ID, Y4APK_Members::REJECT, true)); ?></div><?php endif; ?>
			<?php $messages(); ?>
			<div class="acc-note acc-note-err" data-wiz-err role="alert" hidden></div>
			<?php $form_open($editing ? 'edit' : 'share', ' novalidate enctype="multipart/form-data"'); ?>
			<input type="hidden" name="package" value="<?php echo esc_attr($v('package')); ?>" data-f="package">
			<input type="hidden" name="update_of" value="<?php echo esc_attr($update_of ?: ''); ?>" data-f="update_of">

			<!-- 1. What -->
			<section class="acc-wiz-step" data-step="1">
				<h2 class="acc-wiz-title"><?php echo esc_html($kind === 'app' ? __('Which app is it?', 'y4apk') : __('Which game is it?', 'y4apk')); ?></h2>
				<p class="acc-wiz-lead"><?php esc_html_e('Paste its Google Play link, or type its name. We fill in the rest.', 'y4apk'); ?></p>
				<input type="hidden" name="kind" value="<?php echo esc_attr($kind === 'app' ? 'app' : 'game'); ?>" data-f="kind"><?php // game or app: the switch above the wizard (2.45) ?>
				<?php $by_hand = !empty($c['old']['name']) && empty($c['old']['package']) && empty($c['old']['update_of']) && empty($c['old']['play']); ?>
				<div class="acc-wiz-scope shr-where" role="radiogroup" aria-label="<?php esc_attr_e('Where it is from', 'y4apk'); ?>">
					<label class="acc-wiz-opt<?php echo $by_hand ? '' : ' is-on'; ?>"><input type="radio" name="where" value="play" data-where <?php checked(!$by_hand); ?>><span class="acc-wiz-opt-text"><b><?php esc_html_e('On Google Play', 'y4apk'); ?></b><small><?php echo esc_html($kind === 'app' ? __('Find it by name or its Google Play link', 'y4apk') : __('Find it by name or its Google Play link', 'y4apk')); ?></small></span><span class="acc-wiz-opt-art"><?php echo $ic('play'); ?></span></label>
					<label class="acc-wiz-opt<?php echo $by_hand ? ' is-on' : ''; ?>"><input type="radio" name="where" value="hand" data-where <?php checked($by_hand); ?>><span class="acc-wiz-opt-text"><b><?php esc_html_e('Not on Google Play', 'y4apk'); ?></b><small><?php esc_html_e('Add its name and icon by hand', 'y4apk'); ?></small></span><span class="acc-wiz-opt-art"><?php echo $ic('hand'); ?></span></label>
				</div>
				<div class="shr-findpart" data-findpart>
					<div class="acc-wiz-search shr-find" data-findbox>
						<?php echo $ic('search'); ?>
						<input type="text" data-q autocomplete="off" spellcheck="false" maxlength="200" placeholder="<?php esc_attr_e('Name, play.google.com/store/apps/details?id=… or a package', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Find the game or app', 'y4apk'); ?>">
						<span class="shr-spin" data-spin hidden></span>
					</div>
					<div class="shr-results" data-results hidden></div>
					<div class="acc-wiz-chosen shr-chosen" data-chosen hidden></div>
					<?php if ($sh['popular']) : ?>
					<div class="shr-popular" data-popular>
						<p class="acc-wiz-label"><?php echo esc_html($kind === 'app' ? __('Popular apps on Y4APK', 'y4apk') : __('Popular games on Y4APK', 'y4apk')); ?></p>
						<div class="acc-wiz-popular">
							<?php foreach ($sh['popular'] as $i => $g) : ?><button type="button" class="acc-wiz-game" data-pop="<?php echo (int) $i; ?>" title="<?php echo esc_attr($g['title']); ?>"><?php if ($g['icon'] !== '') : ?><img src="<?php echo esc_url($g['icon']); ?>" alt="" width="40" height="40" loading="lazy"><?php else : ?><span class="shr-hit-q">?</span><?php endif; ?><span><?php echo esc_html($g['name'] !== '' ? $g['name'] : $g['title']); ?></span></button><?php endforeach; ?>
						</div>
						<p class="shr-pop-tip"><?php esc_html_e('Have your own file of one of these? Pick it: your version goes on its page, with your name.', 'y4apk'); ?></p>
					</div>
					<?php endif; ?>
				</div>
				<div class="shr-manual" data-manual hidden>
					<label class="shr-icon-pick" data-iconpick>
						<input type="file" name="icon" accept="image/png,image/jpeg,image/webp" data-icon-file>
						<img data-icon-preview src="" alt="" hidden><span class="shr-icon-empty"><?php echo $ic('image', 26); ?><small><?php esc_html_e('Icon', 'y4apk'); ?></small></span>
					</label>
					<div class="acc-field">
						<label for="f-name"><?php esc_html_e('Its name', 'y4apk'); ?></label>
						<div class="acc-input"><input id="f-name" type="text" name="name" value="<?php echo esc_attr($v('name')); ?>" maxlength="80" placeholder="<?php esc_attr_e('Like Minecraft or CapCut', 'y4apk'); ?>" data-f="name"></div>
						<p class="acc-hint"><?php esc_html_e('The icon: PNG, JPG or WebP, square, at least 96 × 96.', 'y4apk'); ?></p>
					</div>
				</div>
				<div class="acc-wiz-nav"><span></span><button type="button" class="acc-btn acc-btn-primary" data-next><?php esc_html_e('Continue', 'y4apk'); ?> <?php echo $ic('arrow'); ?></button></div>
			</section>

			<!-- 2. Category -->
			<section class="acc-wiz-step" data-step="2" hidden>
				<h2 class="acc-wiz-title"><?php esc_html_e('Pick its category', 'y4apk'); ?></h2>
				<p class="acc-wiz-lead" data-cat-lead><?php esc_html_e('Where people will find it on the site.', 'y4apk'); ?></p>
				<div class="shr-cats" data-cats role="radiogroup"></div>
				<input type="hidden" name="cat" value="<?php echo esc_attr((int) $v('cat', 0)); ?>" data-f="cat">
				<div class="acc-wiz-nav"><button type="button" class="acc-btn acc-btn-ghost" data-back><?php echo $ic('back'); ?> <?php esc_html_e('Back', 'y4apk'); ?></button><button type="button" class="acc-btn acc-btn-primary" data-next><?php esc_html_e('Continue', 'y4apk'); ?> <?php echo $ic('arrow'); ?></button></div>
			</section>

			<!-- 3. Details -->
			<section class="acc-wiz-step" data-step="3" hidden>
				<h2 class="acc-wiz-title"><?php esc_html_e('About this file', 'y4apk'); ?></h2>
				<p class="acc-wiz-lead"><?php esc_html_e('What people get when they download it.', 'y4apk'); ?></p>
				<div class="acc-field shr-title">
					<label for="f-title"><?php esc_html_e('Post title', 'y4apk'); ?> <small><?php esc_html_e('(optional)', 'y4apk'); ?></small></label>
					<span class="acc-wiz-count" data-count-for="f-title"></span>
					<div class="acc-input"><input id="f-title" type="text" name="post_title" value="<?php echo esc_attr($v('own_title')); ?>" maxlength="100" autocomplete="off" data-own-title data-counted></div>
					<p class="acc-hint"><?php esc_html_e('A clear title of your own helps people find your post, here and on Google: the game’s or app’s name first, then what makes your version different, such as its MOD or the version. Up to 100 characters, no links. Left empty, the post gets the title shown in grey.', 'y4apk'); ?></p>
				</div>
				<?php if (class_exists('Y4APK_Cover')) { Y4APK_Cover::field($editing ? (int) $sh['post']->ID : 0); } ?>
				<div class="shr-row2">
					<div class="acc-field">
						<label for="f-version"><?php esc_html_e('Version', 'y4apk'); ?></label>
						<div class="acc-input"><input id="f-version" type="text" name="version" value="<?php echo esc_attr($v('version')); ?>" maxlength="40" inputmode="decimal" placeholder="1.2.3" data-f="version"></div>
						<p class="acc-hint" data-version-hint></p>
					</div>
					<div class="acc-field">
						<label for="f-requires"><?php esc_html_e('Needs Android', 'y4apk'); ?> <small><?php esc_html_e('(optional)', 'y4apk'); ?></small></label>
						<div class="acc-input"><input id="f-requires" type="text" name="requires" value="<?php echo esc_attr($v('requires')); ?>" maxlength="8" inputmode="decimal" placeholder="8.0"></div>
					</div>
				</div>
				<p class="acc-wiz-label"><?php esc_html_e('What is modded', 'y4apk'); ?></p>
				<div class="acc-wiz-chips shr-mods" data-mods>
					<label class="acc-chip shr-orig"><input type="checkbox" name="mod[]" value="Original" <?php checked(in_array('Original', $mods, true)); ?> data-orig><span><?php esc_html_e('Original (no mod)', 'y4apk'); ?></span></label>
					<?php foreach (Y4APK_Share::MODS as $m) : ?><label class="acc-chip"><input type="checkbox" name="mod[]" value="<?php echo esc_attr($m); ?>" <?php checked(in_array($m, $mods, true)); ?>><span><?php echo esc_html($m); ?></span></label><?php endforeach; ?>
				</div>
				<div class="acc-field shr-more">
					<div class="acc-input"><input type="text" name="mod_more" value="<?php echo esc_attr($mod_more); ?>" maxlength="80" placeholder="<?php esc_attr_e('Something else? Like Unlimited Coins, Max Level', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Other MOD features', 'y4apk'); ?>"></div>
				</div>
				<div class="acc-field acc-wiz-about">
					<label for="f-desc"><?php esc_html_e('A few words', 'y4apk'); ?> <small><?php esc_html_e('(optional)', 'y4apk'); ?></small></label>
					<div class="acc-input"><textarea id="f-desc" name="desc" rows="4" maxlength="1500" placeholder="<?php esc_attr_e('What the MOD does, how to install it, what to watch out for…', 'y4apk'); ?>" data-counted><?php echo esc_textarea($v('desc')); ?></textarea></div>
					<span class="acc-wiz-count" data-count-for="f-desc"></span>
				</div>
				<div class="acc-wiz-nav"><button type="button" class="acc-btn acc-btn-ghost" data-back><?php echo $ic('back'); ?> <?php esc_html_e('Back', 'y4apk'); ?></button><button type="button" class="acc-btn acc-btn-primary" data-next><?php esc_html_e('Next: the file', 'y4apk'); ?> <?php echo $ic('arrow'); ?></button></div>
			</section>

			<!-- 4. The file -->
			<section class="acc-wiz-step" data-step="4" hidden>
				<h2 class="acc-wiz-title"><?php esc_html_e('Upload the file', 'y4apk'); ?></h2>
				<p class="acc-wiz-lead"><?php esc_html_e('It goes straight to our cloud storage. Keep this page open until it says Uploaded; if the connection drops, it carries on by itself.', 'y4apk'); ?></p>
				<?php foreach ([0, 1] as $slot) : ?>
				<div class="shr-slot" data-slot="<?php echo (int) $slot; ?>" <?php echo $slot ? 'hidden' : ''; ?>>
					<?php if ($slot) : ?><p class="acc-wiz-label"><?php esc_html_e('Its OBB or data file', 'y4apk'); ?></p><?php endif; ?>
					<label class="shr-drop" data-drop>
						<input type="file" accept=".apk,.xapk,.apks,.apkm,.zip,.obb" data-pickfile>
						<span class="shr-drop-ico"><?php echo $ic('cloud', 30); ?></span>
						<b><?php echo esc_html($slot ? __('Choose the OBB or ZIP', 'y4apk') : __('Choose the file, or drop it here', 'y4apk')); ?></b>
						<small><?php echo esc_html(sprintf(__('APK · XAPK · APKS · APKM · ZIP · OBB — up to %s', 'y4apk'), size_format($sh['max']))); ?></small>
					</label>
					<div class="shr-up" data-up hidden>
						<span class="shr-type" data-type>APK</span>
						<div class="shr-up-main">
							<b data-fname></b>
							<div class="shr-bar"><i data-bar></i></div>
							<p class="shr-stat"><span data-pct>0%</span><span data-fstat></span></p>
						</div>
						<div class="shr-up-acts">
							<button type="button" class="acc-btn acc-btn-small" data-pause><?php esc_html_e('Pause', 'y4apk'); ?></button>
							<button type="button" class="acc-ico acc-ico-danger" data-cancel title="<?php esc_attr_e('Remove', 'y4apk'); ?>" aria-label="<?php esc_attr_e('Remove', 'y4apk'); ?>"><?php echo $ic('x', 16); ?></button>
						</div>
					</div>
					<input type="hidden" name="file[]" value="" data-key>
				</div>
				<?php endforeach; ?>
				<button type="button" class="acc-add shr-add2" data-add2 hidden><?php echo $ic('plus', 16); ?> <?php esc_html_e('Add its OBB or data file (optional)', 'y4apk'); ?></button>
				<div class="acc-wiz-nav"><button type="button" class="acc-btn acc-btn-ghost" data-back><?php echo $ic('back'); ?> <?php esc_html_e('Back', 'y4apk'); ?></button><button type="button" class="acc-btn acc-btn-primary" data-next><?php esc_html_e('Continue to review', 'y4apk'); ?> <?php echo $ic('arrow'); ?></button></div>
			</section>

			<!-- 5. Review -->
			<section class="acc-wiz-step" data-step="5" hidden>
				<h2 class="acc-wiz-title"><?php esc_html_e('Review and send', 'y4apk'); ?></h2>
				<p class="acc-wiz-lead"><?php esc_html_e('One more look. It stays private until we check it, usually within a day.', 'y4apk'); ?></p>
				<div class="shr-card" data-review>
					<span class="shr-card-icon"><img data-r-icon src="" alt="" hidden></span>
					<div class="shr-card-body">
						<b class="shr-card-title" data-r-title></b>
						<p class="shr-card-meta"><span data-r-cat></span><span data-r-ver></span><span>@<?php echo esc_html($me->user_login); ?></span></p>
						<p class="shr-card-mod" data-r-mod></p>
						<ul class="shr-card-files" data-r-files></ul>
					</div>
				</div>
				<?php if (class_exists('Y4APK_Paid')) { Y4APK_Paid::wizard_box($me, 'file', $editing ? (int) $sh['post']->ID : 0); } // 2.69.2: free or for coins ?>
				<ul class="acc-wiz-rules">
					<li><?php esc_html_e('We check every file before it goes live, and email you when it does.', 'y4apk'); ?></li>
					<li><?php esc_html_e('No viruses, no files that steal accounts, nothing you may not share: accounts that send them are closed.', 'y4apk'); ?></li>
				</ul>
				<label class="shr-agree"><input type="checkbox" name="agree" value="1" data-agree <?php checked((bool) $v('agree', false)); ?>><span><?php esc_html_e('I checked this file: it is safe, and it is fine to share it.', 'y4apk'); ?></span></label>
				<?php echo $M::trap_fields(); $M::turnstile_widget(); ?>
				<div class="acc-wiz-nav"><button type="button" class="acc-btn acc-btn-ghost" data-back><?php echo $ic('back'); ?> <?php esc_html_e('Back', 'y4apk'); ?></button><button class="acc-btn acc-btn-primary" type="submit" data-send><?php echo esc_html($editing ? __('Save and send for review', 'y4apk') : __('Send for review', 'y4apk')); ?></button></div>
			</section>
			</form>
		</div>
	</div>
</div>
<?php // y4apk-pwa-…: left alone by the speed plugin's script delay, so the steps work at once ?>
<script id="y4apk-pwa-share" nowprocket data-no-optimize="1" data-noptimize="1" data-no-defer="1" data-no-delay="1" data-cfasync="false" data-pagespeed-no-defer>
(function () {
	var w = document.querySelector('[data-shr]');
	if (!w || !w.querySelector('form') || !window.fetch || !window.FormData) { return; }
	var C = JSON.parse(w.getAttribute('data-cfg')), T = C.T, f = w.querySelector('form');
	var $ = function (s, el) { return (el || w).querySelector(s); }, $$ = function (s, el) { return [].slice.call((el || w).querySelectorAll(s)); };
	var field = function (k) { return $('[data-f="' + k + '"]'); };
	var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; };
	var num = function (v) { var m = String(v || '').match(/\d+(?:\.\d+)*/); return m ? m[0] : ''; };
	var fmt = function (b) { return b >= 1073741824 ? (b / 1073741824).toFixed(2) + ' GB' : (b / 1048576).toFixed(b >= 104857600 ? 0 : 1) + ' MB'; };
	var post = function (action, data) {
		var fd = new FormData(); fd.append('action', 'y4apk_share_' + action); fd.append('nonce', C.nonce);
		Object.keys(data || {}).forEach(function (k) { var v = data[k]; if (Array.isArray(v)) { v.forEach(function (x, i) { fd.append(k + '[' + (x && x.i != null ? x.i : i) + ']', x && x.v != null ? x.v : x); }); } else { fd.append(k, v); } });
		return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin', cache: 'no-store' }).then(function (r) { return r.json(); }).then(function (j) { if (!j || !j.success) { var e = new Error(j && j.data && j.data.message || 'Error'); e.gone = j && j.data && j.data.gone; throw e; } return j.data; });
	};
	w.classList.add('is-js');
	var err = $('[data-wiz-err]'), steps = $$('[data-step]'), cur = 1, reached = 1, pick = C.pick;
	var kind = function () { return field('kind').value === 'app' ? 'app' : 'game'; };

	/* ---- step 1: finding it ---- */
	var q = $('[data-q]'), res = $('[data-results]'), chosen = $('[data-chosen]'), spin = $('[data-spin]'), manual = $('[data-manual]'), timer = 0, asked = '';
	var card = function (x, sub, badge, i, group) {
		return '<button type="button" class="shr-hit" data-hit="' + group + ':' + i + '">' + (x.icon ? '<img src="' + esc(x.icon) + '" alt="" width="48" height="48" loading="lazy">' : '<span class="shr-hit-q">?</span>') +
			'<span class="shr-hit-text"><b>' + esc(x.title) + '</b><small>' + esc(sub) + '</small></span>' + (badge ? '<em class="shr-hit-badge' + (badge === T.newpost ? ' is-new' : '') + '">' + esc(badge) + '</em>' : '') + '</button>';
	};
	var found = { site: [], play: [] };
	function find() {
		var s = q.value.trim();
		if (s.length < 2 || s === asked) { if (s.length < 2) { res.hidden = true; } return; }
		asked = s; spin.hidden = false;
		post('find', { q: s, kind: kind() }).then(function (d) {
			if (q.value.trim() !== s) { return; }
			found = d; spin.hidden = true;
			var h = '';
			if (d.play.length) { h += '<p class="shr-group">' + esc(T.onplay) + '</p>' + d.play.map(function (x, i) { return card(x, [x.developer, x.rating ? '★ ' + x.rating : '', x.version ? 'v' + x.version : ''].filter(Boolean).join(' · '), x.have ? T.have : T.newpost, i, 'play'); }).join(''); }
			if (d.site.length) { h += '<p class="shr-group">' + esc(T.onsite) + '</p>' + d.site.map(function (x, i) { return card(x, x.version ? 'v' + x.version : '', T.have, i, 'site'); }).join(''); }
			if (d.busy) { h = '<p class="shr-note">' + esc(T.busy) + '</p>' + h; }
			res.innerHTML = h || '<p class="shr-note">' + esc(T.nothing) + '</p>';
			res.hidden = false;
		}).catch(function (e) { spin.hidden = true; res.innerHTML = '<p class="shr-note">' + esc(e.message) + '</p>'; res.hidden = false; asked = ''; });
	}
	q.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(find, 450); });
	q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(timer); find(); } });
	function choose(p) {
		pick = p;
		field('package').value = p && p.package ? p.package : '';
		field('update_of').value = p && p.type === 'site' ? p.id : (p && p.have ? p.have.id : '');
		if (p && p.type !== 'manual') { field('name').value = p.have ? (p.have.name || p.title) : (p.name || p.title); }
		var hand = !!p && p.type === 'manual';
		manual.hidden = !hand;
		$('[data-findpart]').hidden = hand;
		$('[data-findbox]').hidden = !!p && !hand;
		var pop = $('[data-popular]');
		if (pop) { pop.hidden = !!p; }
		$$('[data-where]').forEach(function (r) { r.checked = r.value === (hand ? 'hand' : 'play'); r.closest('.acc-wiz-opt').classList.toggle('is-on', r.checked); });
		res.hidden = true;
		if (p && p.type !== 'manual') {
			var site = p.type === 'site' ? p : p.have;
			chosen.innerHTML = (p.icon ? '<img src="' + esc(p.icon) + '" alt="" width="56" height="56">' : '<span class="acc-wiz-q">?</span>') +
				'<span><b>' + esc(p.type === 'site' && p.name ? p.name : p.title) + '</b><small>' + esc(site ? T.newer.replace('%s', site.version || '?') : [p.developer, p.genre].filter(Boolean).join(' · ')) + '</small></span>' +
				'<button type="button" class="acc-wiz-link" data-unpick>' + esc(<?php echo wp_json_encode(__('Change', 'y4apk')); ?>) + '</button>';
			chosen.hidden = false;
			var ver = field('version');
			if (!ver.value && p.version) { ver.value = p.version; }
			$('[data-version-hint]').textContent = p.version ? <?php echo wp_json_encode(__('Google Play has v%s.', 'y4apk')); ?>.replace('%s', p.version) : '';
		} else {
			chosen.hidden = true;
		}
		head(); cats();
	}
	w.addEventListener('click', function (e) {
		var t = e.target.closest('[data-hit],[data-pop],[data-unpick],[data-next],[data-back],[data-goto],[data-cat],[data-add2]');
		if (!t) { return; }
		if (t.hasAttribute('data-hit')) { var g = t.getAttribute('data-hit').split(':'), x = found[g[0]][+g[1]]; choose(Object.assign({ type: g[0] }, x)); }
		else if (t.hasAttribute('data-unpick')) { choose(null); q.focus(); }
		else if (t.hasAttribute('data-pop')) { choose(Object.assign({ type: 'site' }, C.popular[+t.getAttribute('data-pop')])); }
		else if (t.hasAttribute('data-next')) { next(); }
		else if (t.hasAttribute('data-back')) { go(cur - 1); }
		else if (t.hasAttribute('data-goto')) { var n = +t.getAttribute('data-goto'); if (n <= reached) { go(n); } }
		else if (t.hasAttribute('data-cat')) { field('cat').value = t.getAttribute('data-cat'); cats(); }
		else if (t.hasAttribute('data-add2')) { slots[1].el.hidden = false; t.hidden = true; }
	});
	// On Google Play / Not on Google Play (2.49), as the script wizard's One game / Any game
	$$('[data-where]').forEach(function (r) {
		r.addEventListener('change', function () {
			if (!r.checked) { return; }
			if (r.value === 'hand') { choose({ type: 'manual', title: field('name').value }); field('name').focus(); }
			else { choose(null); q.focus(); }
		});
	});
	var iconFile = $('[data-icon-file]'), iconPrev = $('[data-icon-preview]');
	iconFile.addEventListener('change', function () {
		var fl = iconFile.files[0];
		if (fl && /^image\//.test(fl.type)) { iconPrev.src = URL.createObjectURL(fl); iconPrev.hidden = false; if (pick) { pick.icon = iconPrev.src; } }
	});
	field('name').addEventListener('input', function () { if (pick && pick.type === 'manual') { pick.title = this.value; } head(); });

	/* ---- step 2: the category ---- */
	function cats() {
		var box = $('[data-cats]'), list = C.cats[kind()] || [], val = +field('cat').value, site = pick && (pick.type === 'site' || pick.have);
		$('[data-cat-lead]').textContent = site ? T.same : <?php echo wp_json_encode(__('Where people will find it on the site.', 'y4apk')); ?>;
		box.hidden = !!site;
		if (!val && pick && pick.genre) { list.forEach(function (c) { if (c.name.toLowerCase() === String(pick.genre).toLowerCase()) { val = c.id; field('cat').value = c.id; } }); }
		box.innerHTML = list.map(function (c) {
			return '<button type="button" class="shr-cat' + (c.id === val ? ' is-on' : '') + '" data-cat="' + c.id + '" role="radio" aria-checked="' + (c.id === val) + '">' +
				(c.icon ? '<img src="' + esc(c.icon) + '" alt="" width="28" height="28" loading="lazy">' : '<i>' + esc(c.name.charAt(0)) + '</i>') + '<span>' + esc(c.name) + '</span></button>';
		}).join('');
	}

	/* ---- step 3 ---- */
	var modBox = $('[data-mods]');
	modBox.addEventListener('change', function (e) {
		if (e.target.hasAttribute('data-orig') && e.target.checked) { $$('input', modBox).forEach(function (x) { if (x !== e.target) { x.checked = false; } }); }
		else if (e.target.checked) { $('[data-orig]').checked = false; }
	});
	$$('[data-counted]').forEach(function (t) { var out = $('[data-count-for="' + t.id + '"]'), up = function () { out.textContent = t.value.length + ' / ' + t.maxLength; }; t.addEventListener('input', up); up(); });

	/* ---- step 4: the file, straight to R2 ---- */
	var LS = 'y4apk_share_up';
	var saved = function () { try { return JSON.parse(localStorage.getItem(LS) || '{}') || {}; } catch (e) { return {}; } };
	var save = function (id, o) { try { var s = saved(); if (o) { s[id] = o; } else { delete s[id]; } localStorage.setItem(LS, JSON.stringify(s)); } catch (e) {} };
	var lock = null;
	function Slot(el, i) {
		var S = this; S.el = el; S.i = i; S.state = 'empty';
		var input = $('[data-pickfile]', el), drop = $('[data-drop]', el), box = $('[data-up]', el), bar = $('[data-bar]', el), pct = $('[data-pct]', el), stat = $('[data-fstat]', el), keyIn = $('[data-key]', el), pauseB = $('[data-pause]', el);
		var file = null, up = null, etags = {}, loaded = {}, xhrs = {}, queue = [], paused = false, tries = {}, samples = [], sig = '';
		var view = function (on) { drop.hidden = on; box.hidden = !on; };
		S.show = function (name, label, done, sizeText) {
			view(true); $('[data-fname]', el).textContent = name; $('[data-type]', el).textContent = label;
			box.classList.toggle('is-done', !!done); pauseB.hidden = !!done;
			if (done) { bar.style.width = '100%'; pct.textContent = T.done; stat.textContent = sizeText || ''; }
		};
		S.reset = function () { S.state = 'empty'; file = up = null; etags = {}; loaded = {}; queue = []; keyIn.value = ''; view(false); input.value = ''; box.classList.remove('is-done', 'is-err'); refresh(); };
		var label = function (n) { var x = n.split('.').pop().toLowerCase(); return ['apk', 'xapk', 'apks', 'apkm', 'zip', 'obb'].indexOf(x) > -1 ? x.toUpperCase() : ''; };
		var total = function () { var s = 0; Object.keys(etags).forEach(function (n) { s += Math.min(up.partSize, file.size - (n - 1) * up.partSize); }); Object.keys(loaded).forEach(function (n) { s += loaded[n]; }); return s; };
		var paint = function () {
			if (!up || S.state === 'done') { return; }
			var t = total(), p = Math.min(100, t / file.size * 100), now = Date.now();
			samples.push([now, t]); while (samples.length > 2 && now - samples[0][0] > 8000) { samples.shift(); }
			var sp = samples.length > 1 ? (t - samples[0][1]) / ((now - samples[0][0]) / 1000) : 0;
			bar.style.width = p.toFixed(1) + '%'; pct.textContent = (p >= 99.95 && S.state !== 'done' ? 99.9 : p).toFixed(1) + '%';
			if (S.state === 'up') { stat.textContent = fmt(t) + ' / ' + fmt(file.size) + (sp > 0 ? ' · ' + fmt(sp) + '/s · ' + T.left.replace('%s', eta((file.size - t) / sp)) : ''); }
		};
		var eta = function (s) { s = Math.max(1, Math.round(s)); return s >= 3600 ? Math.floor(s / 3600) + 'h ' + Math.round(s % 3600 / 60) + 'm' : s >= 60 ? Math.floor(s / 60) + 'm ' + (s % 60) + 's' : s + 's'; };
		function pickFile(fl) {
			if (!fl) { return; }
			var lb = label(fl.name);
			if (!lb) { return say(T.types); }
			if (fl.size > C.max) { return say(T.big.replace('%s', fmt(C.max))); }
			say(''); file = fl; S.state = 'up'; paused = false; etags = {}; loaded = {}; samples = [];
			S.show(fl.name, lb, false); pct.textContent = '0%'; stat.textContent = T.starting; bar.style.width = '0'; box.classList.remove('is-err');
			sig = fl.name + '|' + fl.size + '|' + fl.lastModified; var old = null, s = saved();
			Object.keys(s).forEach(function (id) { if (s[id].sig === sig) { old = id; } });
			post('start', { name: fl.name, size: fl.size, title: field('name').value, version: field('version').value, resume: old || '' }).then(function (d) {
				up = d; up.urls = d.urls || {};
				if (d.resumed && old) { etags = s[old].etags || {}; stat.textContent = T.resumed; }
				save(d.uploadId, { sig: sig, etags: etags, t: Date.now() });
				queue = []; for (var n = 1; n <= d.count; n++) { if (!etags[n]) { queue.push(n); } }
				refresh(); wake(true); pump();
			}).catch(function (e) { fail(e.message, true); });
		}
		function urls(n) {
			if (up.urls[n]) { return Promise.resolve(up.urls[n]); }
			var want = []; for (var k = n; k < n + 50 && k <= up.count; k++) { if (!etags[k]) { want.push(k); } }
			return post('parts', { uploadId: up.uploadId, numbers: want }).then(function (d) { Object.keys(d.urls).forEach(function (k) { up.urls[k] = d.urls[k]; }); return up.urls[n]; });
		}
		function pump() {
			if (paused || S.state !== 'up') { return; }
			while (Object.keys(xhrs).length < 4 && queue.length) { send(queue.shift()); }
			if (!queue.length && !Object.keys(xhrs).length) { finish(); }
		}
		function send(n) {
			xhrs[n] = true;
			urls(n).then(function (u) {
				if (paused || S.state !== 'up') { delete xhrs[n]; queue.unshift(n); return; }
				var x = new XMLHttpRequest(), from = (n - 1) * up.partSize;
				xhrs[n] = x; x.open('PUT', u);
				x.upload.onprogress = function (e) { loaded[n] = e.loaded; paint(); };
				x.onload = function () {
					delete xhrs[n]; delete loaded[n];
					var tag = x.getResponseHeader('ETag');
					if (x.status >= 200 && x.status < 300 && tag) { etags[n] = tag.replace(/"/g, ''); save(up.uploadId, { sig: sig, etags: etags, t: Date.now() }); paint(); pump(); }
					else { if (x.status === 403) { delete up.urls[n]; } again(n); }
				};
				x.onerror = x.ontimeout = function () { delete xhrs[n]; delete loaded[n]; again(n); };
				x.onabort = function () { delete xhrs[n]; delete loaded[n]; };
				x.send(file.slice(from, Math.min(file.size, from + up.partSize)));
			}).catch(function (e) { delete xhrs[n]; if (e.gone) { return fail(e.message, true); } again(n); });
		}
		function again(n) {
			tries[n] = (tries[n] || 0) + 1;
			if (tries[n] > 6) { queue.unshift(n); return fail(T.failed); }
			stat.textContent = T.retry;
			setTimeout(function () { queue.unshift(n); pump(); }, Math.min(30000, 1000 * Math.pow(2, tries[n])));
		}
		function finish() {
			S.state = 'joining'; stat.textContent = T.joining; pauseB.hidden = true;
			var list = Object.keys(etags).map(function (k) { return { i: k, v: etags[k] }; });
			post('complete', { uploadId: up.uploadId, etags: list }).then(function (d) {
				save(up.uploadId, null); S.state = 'done'; keyIn.value = d.key; S.key = d.key; S.label = d.label; S.size = d.size; S.name = file.name; S.sizeText = d.sizeText;
				S.show(file.name, d.label, true, d.sizeText); wake(false); refresh();
			}).catch(function (e) { save(up.uploadId, null); fail(e.message, true); });
		}
		function fail(msg, over) {
			S.state = over ? 'empty' : 'stopped'; paused = !over; box.classList.add('is-err'); stat.textContent = msg; pauseB.textContent = T.resume; pauseB.hidden = !!over;
			if (over) { up = null; setTimeout(function () { if (S.state === 'empty') { S.reset(); say(msg); } }, 2500); }
			wake(false); refresh();
		}
		pauseB.addEventListener('click', function () {
			if (S.state === 'up' && !paused) { paused = true; S.state = 'stopped'; Object.keys(xhrs).forEach(function (n) { if (xhrs[n] && xhrs[n].abort) { queue.unshift(+n); xhrs[n].abort(); } }); xhrs = {}; loaded = {}; pauseB.textContent = T.resume; stat.textContent = T.paused; wake(false); }
			else if (S.state === 'stopped') { paused = false; S.state = 'up'; tries = {}; box.classList.remove('is-err'); pauseB.textContent = T.pause; wake(true); pump(); }
			refresh();
		});
		$('[data-cancel]', el).addEventListener('click', function () {
			if (S.state !== 'empty' && !confirm(T.cancel)) { return; }
			paused = true; Object.keys(xhrs).forEach(function (n) { if (xhrs[n] && xhrs[n].abort) { xhrs[n].abort(); } });
			if (up) { post('abort', { uploadId: up.uploadId, key: keyIn.value }).catch(function () {}); save(up.uploadId, null); }
			else if (keyIn.value) { post('abort', { uploadId: '', key: keyIn.value }).catch(function () {}); }
			S.key = ''; S.reset(); wake(false);
		});
		input.addEventListener('change', function () { pickFile(input.files[0]); });
		['dragover', 'dragenter'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
		['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); if (ev === 'drop' && e.dataTransfer.files[0]) { pickFile(e.dataTransfer.files[0]); } }); });
	}
	var slots = $$('[data-slot]').map(function (el, i) { return new Slot(el, i); });
	(C.files || []).forEach(function (fl, i) { var s = slots[i]; if (!s) { return; } s.el.hidden = false; s.state = 'done'; s.key = fl.key; s.label = fl.label; s.size = fl.size; s.name = fl.name; s.sizeText = fl.sizeText; $('[data-key]', s.el).value = fl.key; s.show(fl.name, fl.label, true, fl.sizeText); });
	function wake(on) { try { if (on && navigator.wakeLock && !lock) { navigator.wakeLock.request('screen').then(function (l) { lock = l; }).catch(function () {}); } else if (!on && lock && !busy()) { lock.release(); lock = null; } } catch (e) {} }
	var busy = function () { return slots.some(function (s) { return s.state === 'up' || s.state === 'joining' || s.state === 'stopped'; }); };
	function refresh() { $('[data-add2]').hidden = !(slots[0].state === 'done' && slots[1].el.hidden); head(); }
	window.addEventListener('beforeunload', function (e) { if (busy()) { e.preventDefault(); e.returnValue = T.leave; return T.leave; } });

	/* ---- the steps ---- */
	function say(m) { err.textContent = m; err.hidden = !m; if (m) { err.scrollIntoView({ block: 'nearest', behavior: 'smooth' }); } }
	function problem(n) {
		if (n === 1) { if (!pick || !field('name').value.trim()) { return T.find; } if (pick.type === 'manual' && !iconFile.files.length && !pick.icon) { return T.icon; } }
		if (n === 2 && !(pick && (pick.type === 'site' || pick.have)) && !+field('cat').value) { return T.cat; }
		if (n === 3 && !num(field('version').value)) { return T.version; }
		if (n === 4) { if (busy()) { return T.wait; } if (slots[0].state !== 'done') { return T.file; } }
		if (n === 5 && !$('[data-agree]').checked) { return T.agree; }
		return '';
	}
	function next() { var p = problem(cur); if (p) { return say(p); } say(''); go(cur + 1); }
	function go(n) {
		n = Math.max(1, Math.min(5, n)); cur = n; reached = Math.max(reached, n);
		steps.forEach(function (s) { s.hidden = +s.getAttribute('data-step') !== n; });
		$$('.acc-wiz-steps li').forEach(function (li) { var k = +li.getAttribute('data-goto'); li.classList.toggle('is-on', k === n); li.classList.toggle('is-done', k < reached && k !== n && !problem(k)); li.classList.toggle('can-go', k <= reached); });
		if (n === 5) { review(); tsFresh(); }
		if (n === 3) { titleHint(); }
		head();
		var top = w.getBoundingClientRect().top + window.pageYOffset - 80; if (window.pageYOffset > top) { window.scrollTo({ top: top, behavior: 'smooth' }); }
	}
	var catName = function () { var id = +field('cat').value, n = ''; (C.cats[kind()] || []).forEach(function (c) { if (c.id === id) { n = c.name; } }); return n; };
	var mods = function () { var o = $$('input[name="mod[]"]:checked').map(function (x) { return x.value; }), more = $('input[name="mod_more"]').value.trim(); if (more) { o.push(more); } return o.indexOf('Original') > -1 ? [] : o; };
	function autoTitle() {
		var name = field('name').value.trim() || (pick && pick.title) || '', m = mods(), v = num(field('version').value), lb = slots[0].label || 'APK', type = ['XAPK', 'APKS', 'APKM'].indexOf(lb) > -1 ? lb : 'APK';
		return (name + (m.length ? ' MOD ' : ' ') + type + (v ? ' v' + v : '') + (m.length ? ' (' + m.join(', ') + ')' : '')).trim();
	}
	/* ---- The post's title (2.52): the member's own, with the name in it, or the usual one (shown in grey in the box) ---- */
	var own = $('[data-own-title]');
	function title() {
		var t = own ? own.value.replace(/\s+/g, ' ').trim() : '', name = field('name').value.trim() || (pick && pick.title) || '';
		if (!t) { return autoTitle(); }
		return !name || t.toLowerCase().indexOf(name.toLowerCase()) > -1 ? t : name + ' – ' + t;
	}
	var titleHint = function () { if (own) { own.placeholder = autoTitle(); } };
	f.addEventListener('input', titleHint);
	f.addEventListener('change', titleHint);
	function head() {
		var hi = $('[data-head-icon]');
		if (pick && pick.icon) { hi.innerHTML = '<img src="' + esc(pick.icon) + '" alt="">'; }
		$('[data-step-sub="1"]').textContent = pick && reached > 1 ? (field('name').value || pick.title || '') : '';
		$('[data-step-sub="2"]').textContent = reached > 2 ? (pick && (pick.type === 'site' || pick.have) ? T.same : catName()) : '';
		$('[data-step-sub="3"]').textContent = reached > 3 && num(field('version').value) ? 'v' + num(field('version').value) : '';
		var n = slots.filter(function (s) { return s.state === 'done'; }).length;
		$('[data-step-sub="4"]').textContent = n ? (n === 1 ? slots[0].sizeText : T.files.replace('%d', n)) : '';
	}
	function review() {
		var ri = $('[data-r-icon]');
		if (pick && pick.icon) { ri.src = pick.icon; ri.hidden = false; } else { ri.hidden = true; }
		$('[data-r-title]').textContent = title();
		$('[data-r-cat]').textContent = pick && (pick.type === 'site' || pick.have) ? T.same : catName();
		$('[data-r-ver]').textContent = 'v' + num(field('version').value);
		var m = mods(); $('[data-r-mod]').textContent = m.length ? 'MOD: ' + m.join(' · ') : <?php echo wp_json_encode(__('Original, no mod', 'y4apk')); ?>;
		$('[data-r-files]').innerHTML = slots.filter(function (s) { return s.state === 'done'; }).map(function (s) { return '<li><span class="shr-type">' + esc(s.label) + '</span><span>' + esc(s.name) + '</span><b>' + esc(s.sizeText) + '</b></li>'; }).join('');
	}
	/* ---- Cloudflare's check: a token lives 5 minutes and an upload can take longer, so a fresh one at the last step ---- */
	var ts = function () { return w.querySelector('.cf-turnstile'); }, tsAt = Date.now();
	var tsToken = function () { var i = f.querySelector('[name="cf-turnstile-response"]'); return i ? i.value : ''; };
	function tsFresh() { var el = ts(); if (el && window.turnstile && Date.now() - tsAt > 200000) { try { window.turnstile.reset(el); tsAt = Date.now(); } catch (e) {} } }
	var sending = false;
	f.addEventListener('submit', function (e) {
		for (var n = 1; n <= 5; n++) { var p = problem(n); if (p) { e.preventDefault(); go(n); say(p); return; } }
		var b = $('[data-send]');
		if (ts() && !tsToken()) { // the check has not answered yet: wait for it, then send
			e.preventDefault();
			if (sending) { return; }
			sending = true; b.disabled = true; say('');
			var t0 = Date.now(), wait = setInterval(function () {
				if (tsToken()) { clearInterval(wait); f.submit(); }
				else if (Date.now() - t0 > 25000) { clearInterval(wait); sending = false; b.disabled = false; say(<?php echo wp_json_encode(__('Could not confirm you are human. Tick the box above the button, then send again.', 'y4apk')); ?>); }
			}, 300);
			return;
		}
		setTimeout(function () { b.disabled = true; }, 0);
	});
	if (pick) { choose(pick); } else { cats(); }
	reached = Math.max(1, Math.min(5, C.start));
	go(C.start);
})();
</script>
