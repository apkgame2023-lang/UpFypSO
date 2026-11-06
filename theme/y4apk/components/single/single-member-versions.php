<?php
// Versions from members (2.46): on a game or app, the versions members shared as their own posts; on one of those, the main post and the others.
if (class_exists('Y4APK_Versions')) {
	Y4APK_Versions::render(get_the_ID());
}
