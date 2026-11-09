<?php
/**
 * The look of Y4APK's own admin pages (2.58): one set of parts — the page's
 * head (a picture, the name, a line about it, its buttons), number cards,
 * cards with a head, two columns, tabs, chips, tables and a save bar that
 * stays at the bottom — in the Dashboard's style, across the whole width.
 * Pages put their content in <div class="wrap y4ui …"> and call css() once.
 */

defined('ABSPATH') || exit;

class Y4APK_Admin_UI {

	private static $printed = false;

	public static function init() {
		add_filter('admin_viewport_meta', [__CLASS__, 'viewport']);
	}

	/** Y4APK's own admin pages on a phone (2.60.1): the page stays at its size — a pinch or a tap no longer zooms it. */
	public static function viewport($content) {
		$page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return strpos($page, 'y4apk') === 0 ? 'width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no' : $content;
	}

	/** Small pictures for the pages' heads. */
	public static function icon($name, $size = 24) {
		$p = [
			'globe'   => '<path d="M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20Zm6.9 6h-2.95a15.7 15.7 0 0 0-1.38-3.56A8.03 8.03 0 0 1 18.9 8ZM12 4.04c.83 1.2 1.48 2.53 1.91 3.96h-3.82c.43-1.43 1.08-2.76 1.91-3.96ZM4.26 14a8.2 8.2 0 0 1 0-4h3.38a16.5 16.5 0 0 0 0 4H4.26Zm.82 2h2.95c.32 1.25.78 2.45 1.38 3.56A8 8 0 0 1 5.08 16Zm2.95-8H5.08a8 8 0 0 1 4.33-3.56A15.7 15.7 0 0 0 8.03 8ZM12 19.96A14.1 14.1 0 0 1 10.09 16h3.82A14.1 14.1 0 0 1 12 19.96ZM14.34 14H9.66a14.7 14.7 0 0 1 0-4h4.68a14.7 14.7 0 0 1 0 4Zm.25 5.56c.6-1.11 1.06-2.31 1.38-3.56h2.95a8.03 8.03 0 0 1-4.33 3.56ZM16.36 14a16.5 16.5 0 0 0 0-4h3.38a8.2 8.2 0 0 1 0 4h-3.38Z"/>',
			'home'    => '<path d="M12 3 2 12h3v8h6v-6h2v6h6v-8h3L12 3Z"/>',
			'support' => '<path d="M12 1a9 9 0 0 0-9 9v7c0 1.66 1.34 3 3 3h3v-8H5v-2a7 7 0 0 1 14 0v2h-4v8h4v1h-7v2h6c1.66 0 3-1.34 3-3V10a9 9 0 0 0-9-9Z"/>',
			'update'  => '<path d="M21 10.12h-6.78l2.74-2.82a7.03 7.03 0 0 0-9.88-.1 6.88 6.88 0 0 0 0 9.8 7.01 7.01 0 0 0 9.88 0A6.5 6.5 0 0 0 19 12.1h2a8.4 8.4 0 0 1-2.64 6.29 9.08 9.08 0 0 1-12.72 0 8.86 8.86 0 0 1-.03-12.6 8.98 8.98 0 0 1 12.64 0L21 3v7.12ZM12.5 8v4.25l3.5 2.08-.72 1.21L11 13V8h1.5Z"/>',
			'check'   => '<path d="M9 16.2 4.8 12l-1.4 1.4L9 19 21 7l-1.4-1.4L9 16.2Z"/>',
			'eye'     => '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5ZM12 17a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6Z"/>',
			'mail'    => '<path d="M20 4H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2Zm0 4-8 5-8-5V6l8 5 8-5v2Z"/>',
			'star'    => '<path d="M12 17.27 18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21 12 17.27Z"/>',
			'shield'  => '<path d="M12 1 3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4Z"/>',
			'page'    => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Zm2 16H8v-2h8v2Zm0-4H8v-2h8v2Zm-3-5V3.5L18.5 9H13Z"/>',
			'text'    => '<path d="M5 4v3h5.5v12h3V7H19V4H5Z"/>',
			'link'    => '<path d="M3.9 12a3.1 3.1 0 0 1 3.1-3.1h4V7H7a5 5 0 0 0 0 10h4v-1.9H7A3.1 3.1 0 0 1 3.9 12ZM8 13h8v-2H8v2Zm9-6h-4v1.9h4a3.1 3.1 0 0 1 0 6.2h-4V17h4a5 5 0 0 0 0-10Z"/>',
			'crown'   => '<path d="M3 7.5 7.5 11 12 4l4.5 7L21 7.5 19.2 18H4.8L3 7.5ZM5 19.5h14V21H5v-1.5Z"/>',
			'wallet'  => '<path d="M21 7.28V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-2.28A2 2 0 0 0 22 15V9a2 2 0 0 0-1-1.72ZM20 9v6h-7V9h7ZM5 19V5h14v2h-6a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h6v2H5Zm11-5.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z"/>',
			'chat'    => '<path d="M20 2H4a2 2 0 0 0-2 2v18l4-4h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2ZM7 9h10v2H7V9Zm6 5H7v-2h6v2Zm4-6H7V6h10v2Z"/>',
			'review'  => '<path d="M19 3h-4.18A3 3 0 0 0 12 1a3 3 0 0 0-2.82 2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V5a2 2 0 0 0-2-2Zm-7 0a1 1 0 1 1 0 2 1 1 0 0 1 0-2Zm-2 14-4-4 1.41-1.41L10 14.17l6.59-6.59L18 9l-8 8Z"/>',
		];
		return '<svg viewBox="0 0 24 24" width="' . (int) $size . '" height="' . (int) $size . '" fill="currentColor" aria-hidden="true">' . ($p[$name] ?? $p['check']) . '</svg>';
	}

	/**
	 * The page's head: [icon, title, sub (HTML allowed: links), actions (HTML), tint].
	 * Ends with WordPress's notices marker, so its messages show under it.
	 */
	public static function head($a) {
		self::css();
		$tint = $a['tint'] ?? 'blue';
		?>
		<div class="y4ui-head">
			<span class="y4ui-badge is-<?php echo esc_attr($tint); ?>"><?php echo self::icon($a['icon'] ?? 'check', 26); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<div class="y4ui-head-t">
				<?php if (!empty($a['back'])) : ?><a class="y4ui-back" href="<?php echo esc_url($a['back'][0]); ?>">← <?php echo esc_html($a['back'][1]); ?></a><?php endif; ?>
				<h1><?php echo $a['title']; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by the caller ?></h1>
				<?php if (!empty($a['sub'])) : ?><p><?php echo wp_kses_post($a['sub']); ?></p><?php endif; ?>
			</div>
			<?php if (!empty($a['actions'])) : ?><div class="y4ui-head-acts"><?php echo $a['actions']; // phpcs:ignore WordPress.Security.EscapeOutput -- built by the caller from escaped parts ?></div><?php endif; ?>
		</div>
		<hr class="wp-header-end">
		<?php
	}

	public static function css() {
		if (self::$printed) {
			return;
		}
		self::$printed = true;
		?>
		<style id="y4apk-admin-ui">
		.y4ui{--ui-ink:#1d2327;--ui-ink2:#50575e;--ui-mute:#8c8f94;--ui-line:#dcdcde;--ui-line2:#f0f0f1;--ui-soft:#f6f7f7;--ui-blue:#2a78d6;--ui-green:#00a32a;--ui-amber:#dba617;--ui-red:#d63638;max-width:none!important;margin:10px 20px 0 2px;padding-bottom:40px;color:var(--ui-ink);font-size:13px}
		.y4ui *,.y4ui *::before,.y4ui *::after{box-sizing:border-box}
		.y4ui [hidden]{display:none!important}
		.y4ui .button{border-radius:8px;min-height:32px;line-height:30px}
		.y4ui .button-large{min-height:40px;line-height:38px;padding:0 18px;font-weight:600}
		.y4ui input[type=text],.y4ui input[type=url],.y4ui input[type=search],.y4ui input[type=email],.y4ui select,.y4ui textarea{border-radius:8px;border-color:#c9cdd2}
		.y4ui input[type=text],.y4ui input[type=url],.y4ui input[type=search],.y4ui input[type=email],.y4ui select{min-height:36px}
		/* Head */
		.y4ui-head{display:flex;align-items:center;gap:16px;flex-wrap:wrap;margin:6px 0 18px}
		.y4ui-badge{flex:none;display:grid;place-items:center;width:52px;height:52px;border-radius:15px;color:#fff;background:linear-gradient(135deg,#46c7ec,#2a78d6);box-shadow:0 12px 22px -12px rgba(42,120,214,.75)}
		.y4ui-badge.is-green{background:linear-gradient(135deg,#4cd38a,#0f9a4f);box-shadow:0 12px 22px -12px rgba(15,154,79,.7)}
		.y4ui-badge.is-violet{background:linear-gradient(135deg,#9d7bff,#5b46e5);box-shadow:0 12px 22px -12px rgba(91,70,229,.7)}
		.y4ui-badge.is-amber{background:linear-gradient(135deg,#ffc04d,#ef8a12);box-shadow:0 12px 22px -12px rgba(239,138,18,.7)}
		.y4ui-head-t{flex:1;min-width:260px}
		.y4ui-head h1{margin:0;padding:0;font-size:26px;font-weight:700;line-height:1.2}
		.y4ui-head p{margin:5px 0 0;max-width:980px;color:var(--ui-ink2);font-size:13px;line-height:1.55}
		.y4ui-back{display:inline-block;margin:0 0 2px;font-weight:600;text-decoration:none}
		.y4ui-head-acts{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
		.y4ui-status{display:inline-flex;align-items:center;gap:8px;max-width:420px;padding:7px 12px;border-radius:999px;background:#fff;border:1px solid var(--ui-line);color:var(--ui-ink2);font-size:12.5px}
		/* Numbers */
		.y4ui-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px;margin:0 0 16px}
		.y4ui-kpi{position:relative;display:flex;flex-direction:column;gap:5px;min-width:0;padding:16px 18px;background:#fff;border:1px solid var(--ui-line);border-radius:14px;color:inherit;text-decoration:none;transition:border-color .15s,box-shadow .15s}
		a.y4ui-kpi:hover{border-color:var(--ui-blue);box-shadow:0 8px 20px -12px rgba(42,120,214,.6);color:inherit}
		.y4ui-kpi.is-on{border-color:var(--ui-blue);box-shadow:0 0 0 1px var(--ui-blue)}
		.y4ui-kpi-l{display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:var(--ui-ink2)}
		.y4ui-kpi-n{font-size:28px;font-weight:700;line-height:1.1;color:var(--ui-ink);overflow-wrap:anywhere}
		.y4ui-kpi-n small{font-size:14px;font-weight:600;color:var(--ui-mute)}
		.y4ui-kpi-s{font-size:12px;color:var(--ui-mute);line-height:1.45}
		.y4ui-kpi.is-red .y4ui-kpi-n{color:var(--ui-red)}.y4ui-kpi.is-green .y4ui-kpi-n{color:#008a20}
		.y4ui-bar{display:block;height:6px;border-radius:6px;background:#eef0f2;overflow:hidden}
		.y4ui-bar i{display:block;height:100%;border-radius:6px;background:var(--ui-blue)}
		.y4ui-bar.is-green i{background:var(--ui-green)}
		/* Cards */
		.y4ui-card{min-width:0;margin:0 0 16px;background:#fff;border:1px solid var(--ui-line);border-radius:14px}
		.y4ui-card-h{display:flex;align-items:center;justify-content:space-between;gap:10px 16px;flex-wrap:wrap;padding:16px 20px;border-bottom:1px solid var(--ui-line2)}
		.y4ui-card-h h2{margin:0;padding:0;font-size:16px;font-weight:700;line-height:1.3}
		.y4ui-card-h p{margin:3px 0 0;color:var(--ui-ink2);line-height:1.5}
		.y4ui-card-b{padding:18px 20px}
		.y4ui-card-b > :first-child{margin-top:0}.y4ui-card-b > :last-child{margin-bottom:0}
		.y4ui-cols{display:grid;grid-template-columns:minmax(0,1fr) 420px;gap:16px;align-items:start}
		.y4ui-cols.is-even{grid-template-columns:minmax(0,1fr) minmax(0,1fr)}
		.y4ui-side{position:sticky;top:48px}
		@media (max-width:1280px){.y4ui-cols,.y4ui-cols.is-even{grid-template-columns:minmax(0,1fr)}.y4ui-side{position:static}}
		/* Tabs, like the Dashboard's range */
		.y4ui-bar-row{display:flex;align-items:center;justify-content:space-between;gap:10px 16px;flex-wrap:wrap;margin:0 0 14px}
		.y4ui-tabs{display:inline-flex;flex-wrap:wrap;gap:2px;padding:3px;border-radius:11px;background:#e3e5e8}
		.y4ui-tabs a{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;color:var(--ui-ink2);font-weight:600;text-decoration:none;white-space:nowrap}
		.y4ui-tabs a:hover{color:var(--ui-ink)}
		.y4ui-tabs a.is-on{background:#fff;color:var(--ui-ink);box-shadow:0 1px 3px rgba(0,0,0,.12)}
		.y4ui-n{display:inline-grid;place-items:center;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:#d4d7db;color:var(--ui-ink);font-size:11px;font-weight:700;line-height:1}
		.y4ui-n.is-red{background:var(--ui-red);color:#fff}
		.y4ui-find{display:flex;gap:6px;margin:0}
		.y4ui-find input[type=search]{width:280px;max-width:100%}
		/* Chips */
		.y4ui-chip{display:inline-flex;align-items:center;gap:5px;height:22px;padding:0 9px;border-radius:999px;background:#f0f0f1;color:#50575e;font-size:12px;font-weight:600;line-height:1;white-space:nowrap}
		.y4ui-chip.is-green{background:#edfaef;color:#00691b}.y4ui-chip.is-amber{background:#fcf9e8;color:#7a5d00}
		.y4ui-chip.is-red{background:#fcf0f1;color:#b32d2e}.y4ui-chip.is-blue{background:#e8f1fb;color:#135e96}
		.y4ui-chip.is-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-weight:500}
		/* Tables */
		.y4ui-table{width:100%;border-collapse:separate;border-spacing:0}
		.y4ui-table th{padding:10px 16px;text-align:left;font-size:11px;font-weight:600;letter-spacing:.05em;text-transform:uppercase;color:var(--ui-mute);background:#fafafa;border-bottom:1px solid var(--ui-line2)}
		.y4ui-table th:first-child{border-top-left-radius:14px}.y4ui-table th:last-child{border-top-right-radius:14px}
		.y4ui-table td{padding:12px 16px;border-bottom:1px solid var(--ui-line2);vertical-align:middle}
		.y4ui-table tbody tr:last-child td{border-bottom:0}
		.y4ui-table tbody tr:hover td{background:#fafcff}
		.y4ui-table .is-off td{color:var(--ui-mute)}
		.y4ui-scroll{overflow-x:auto;border-radius:0 0 14px 14px}
		.y4ui-empty{padding:48px 20px;text-align:center;color:var(--ui-mute);font-size:14px}
		/* Fields: the label over the box */
		.y4ui-fields{display:grid;gap:16px}
		.y4ui-fields.is-two{grid-template-columns:repeat(2,minmax(0,1fr))}
		.y4ui-field{min-width:0}
		.y4ui-field.is-wide{grid-column:1/-1}
		.y4ui-field > label,.y4ui-field > .y4ui-label{display:block;margin:0 0 6px;font-weight:600;color:var(--ui-ink)}
		.y4ui-field input[type=text],.y4ui-field input[type=url],.y4ui-field input[type=search]{width:100%}
		.y4ui-field .description{margin:6px 0 0;color:var(--ui-mute)}
		@media (max-width:960px){.y4ui-fields.is-two{grid-template-columns:minmax(0,1fr)}}
		/* Choices: a box per option, the chosen one outlined */
		.y4ui-opts{display:grid;gap:8px}
		.y4ui-opts.is-three{grid-template-columns:repeat(3,minmax(0,1fr))}
		.y4ui-opt{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid var(--ui-line);border-radius:12px;background:#fff;cursor:pointer;transition:border-color .15s,background-color .15s}
		.y4ui-opt:hover{border-color:#a7aaad}
		.y4ui-opt:has(input:checked){border-color:var(--ui-blue);background:#f3f8fe;box-shadow:0 0 0 1px var(--ui-blue)}
		.y4ui-opt input{flex:none;margin:2px 0 0!important}
		.y4ui-field > label.y4ui-opt{display:flex;margin:0;font-weight:inherit}
		.y4ui-opt b{display:block;font-weight:600;line-height:1.4}
		.y4ui-opt small{display:block;margin-top:3px;color:var(--ui-ink2);font-size:12px;line-height:1.5}
		@media (max-width:1100px){.y4ui-opts.is-three{grid-template-columns:minmax(0,1fr)}}
		/* The save bar */
		.y4ui-savebar{position:sticky;bottom:12px;z-index:20;display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0 0;padding:12px 16px;background:rgba(255,255,255,.95);border:1px solid var(--ui-line);border-radius:14px;box-shadow:0 12px 30px -14px rgba(0,0,0,.3);-webkit-backdrop-filter:blur(8px);backdrop-filter:blur(8px)}
		.y4ui-savebar .y4ui-hint{flex:1;min-width:200px;color:var(--ui-ink2)}
		.y4ui-note{max-width:1100px;margin:12px 0 0;color:var(--ui-mute);font-size:12.5px;line-height:1.6}
		@media (max-width:782px){.y4ui{margin-right:10px}.y4ui-head h1{font-size:22px}.y4ui-badge{width:44px;height:44px;border-radius:13px}.y4ui-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.y4ui-kpi-n{font-size:22px}.y4ui-card-h,.y4ui-card-b{padding-left:14px;padding-right:14px}.y4ui-table th,.y4ui-table td{padding-left:12px;padding-right:12px}.y4ui-find input[type=search]{width:100%}.y4ui-find{flex:1}}
		</style>
		<?php
	}
}

Y4APK_Admin_UI::init();
