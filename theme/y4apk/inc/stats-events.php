<?php
/**
 * The events the app analytics counts, with their labels. Shared by the
 * counter (stats.php, which accepts nothing else) and the Analytics page.
 * The text after ":" in an event key is its detail: a banner mode, an app
 * kind (apk / webapp), an app version or a post ID.
 */
return [
	'banner_seen'   => 'Install banner seen (once per visit)',
	'banner_tap'    => 'Install banner button tapped',
	'banner_close'  => 'Install banner closed',
	'pwa_accepted'  => 'Web app install accepted',
	'pwa_installed' => 'Web app installed',
	'app_open'      => 'Visit inside the app',
	'app_version'   => 'Visit inside the APK, by version',
	'app_daily'     => 'Device using the app that day',
	'app_new'       => 'Device using the app for the first time',
	'file_download' => 'Download button on a download page',
	'direct_link'   => 'Direct Link ad opened',
];
