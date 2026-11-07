<?php
// A member's own upload (2.50: it may have gone live at once, its checks passed): visitors can report it.
if (class_exists('Y4APK_Share') && class_exists('Y4APK_Members_Admin') && Y4APK_Share::is_share(get_the_ID())) {
	Y4APK_Members_Admin::report_ui(get_the_ID(), 'file');
}
// Versions from members (2.46): on a game or app, the versions members shared as their own posts; on one of those, the main post and the others.
if (class_exists('Y4APK_Versions')) {
	Y4APK_Versions::render(get_the_ID());
}
