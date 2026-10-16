<?php
function y4apk_comments($comment, $args, $depth) {
    $default_logo_url = get_template_directory_uri() . '/assets/img/user.png';
    $comment_author_logo = get_avatar_url($comment, array('size' => 50));

    $author_logo_url = !empty($comment_author_logo) ? $comment_author_logo : $default_logo_url;

    $author = get_comment_author() ? get_comment_author() : 'User';
	
    $comment_class = $comment->comment_approved == '0' ? 'comment-pending' : '';
    ?>
    <li id="comment-<?php comment_ID();?>" class="comment-list <?php echo $comment_class; ?>">
		<div class="comment-meta">
			<img src="<?php echo $author_logo_url; ?>" alt="user-logo" width="50" height="50" style="border-radius: 50%;">
			<div class="fw-b"><?php echo $author; ?></div>
            <p><?php printf(_x('%s ago', '%s = human-readable time difference', 'mw'), human_time_diff(get_comment_time('U'), current_time('timestamp'))); ?></p>
        </div>
        <div class="comment-text">
            <?php if ($comment->comment_approved == '0') : ?>
                <span style="color:red;"><strong><?php esc_html_e('Please wait for approval', 'y4apk'); ?></strong></span>
            <?php endif; ?>
            <?php comment_text(); ?>
            <?php comment_reply_link(array_merge($args, array('depth' => $depth, 'max_depth' => $args['max_depth'])));?>
        </div>
    </li>
<div class="comment-divider"></div>
    <?php
}
//Enqueue comment reply
function mw_enqueue_comment_reply() {
    if ( is_singular() && comments_open() && get_option( 'thread_comments' ) ) { 
        wp_enqueue_script( 'comment-reply' ); 
    }
}
add_action( 'wp_enqueue_scripts', 'mw_enqueue_comment_reply' );

//Remove Logged in as text, edit profile link
add_filter( 'comment_form_logged_in', '__return_empty_string' );