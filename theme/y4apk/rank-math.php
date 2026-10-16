<?php

/**
 * Filter to change the Post Meta Table Limit
 */
add_filter('rank_math/seo_analysis/postmeta_table_limit', function ( $limit ) {
		return 500000;
	}
);