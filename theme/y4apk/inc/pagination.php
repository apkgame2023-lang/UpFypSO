<?php
function y4apk_pagination($current_page, $total_pages) {
    if ($total_pages <= 1) {
        return '';
    }
	echo '<div class="navigation"><div class="navigation-in">';
	
	$prev = esc_attr__('Previous Page', 'y4apk');
	$next = esc_attr__('Next Page', 'y4apk');
	echo '<div class="nav-prev-next">';
    if ($current_page > 1) {
        echo '<span class="page_prev" title="'.$prev.'"><a href="'.get_pagenum_link($current_page - 1).'"><svg width="24" height="24"><use xlink:href="#i__arrow-left"></use></svg><span class="sr-only">'.$prev.'</span></a></span>';
    } else {
        echo '<span class="page_prev" title="'.$prev.'"><span><svg width="24" height="24"><use xlink:href="#i__arrow-left"></use></svg><span class="sr-only">'.$prev.'</span></span></span>';
    }
    if ($current_page < $total_pages) {
        echo '<span class="page_next" title="'.$next.'"><a href="'.get_pagenum_link($current_page + 1).'"><svg width="24" height="24"><use xlink:href="#i__arrow-right"></use></svg><span class="sr-only">'.$next.'</span></a></span>';
    } else {
        echo '<span class="page_next" title="'.$next.'"><span><svg width="24" height="24"><use xlink:href="#i__arrow-right"></use></svg><span class="sr-only">'.$next.'</span></span></span>';
    }
	echo '</div>';
	
    $last_displayed_page = 0;
	echo '<div class="pages">';
    for ($i = 1; $i <= $total_pages; $i++) {
        if ($i == 1 || $i == $total_pages || ($i >= $current_page - 1 && $i <= $current_page + 1)) {
            if ($last_displayed_page != $i - 1) {
                echo '<span class="nav_ext">...</span>';
            }
            if ($i == $current_page) {
                echo '<span>'.$i.'</span>';
            } else {
                echo '<a href="'.get_pagenum_link($i).'">'.$i.'</a>';
            }
            $last_displayed_page = $i;
        }
    }
	echo '</div>';
	
	echo '</div></div>';
}