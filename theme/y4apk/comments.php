<?php if (post_password_required()) : ?>
<div class="b-cont">
    <div class="info-line">
        <i class="info-line-icon c-yellow">
            <svg width="24" height="24">
                <use xlink:href="#i__info"></use>
            </svg>
        </i>
        <span><?php _e('This post is password protected. Enter the password to view comments.', 'y4apk'); ?></span>
    </div>
</div>
<?php return; endif; if (!comments_open()) : ?>
<p class="no-comments"><?php esc_html_e('Comments are closed.', 'y4apk'); ?></p>
<?php else : ?>
<div class="block f-app-seo">
    <h3 class="section-title"><?php esc_html_e('Leave a Comment', 'y4apk'); ?></h3>
    <?php
	$commenter = wp_get_current_commenter();
	$req = get_option('require_name_email');
	$aria_req = ($req ? " aria-required='true'" : '');
	$html_req = ($req ? " required='required'" : '');
	$args = array(
		'fields' => apply_filters(
			'comment_form_default_fields', array(
				'author' => '<div><label for="full-name">' . __('Full Name', 'y4apk') . '</label><input type="text" id="author" name="author" value="' . esc_attr($commenter['comment_author']) . '" rows="5"' . $aria_req . $html_req . ' /></div>',
				'email'  => '<div><label for="email">' . __('Email', 'y4apk') . '</label><input type="email" id="email" name="email" value="' . esc_attr($commenter['comment_author_email']) . '" rows="5"' . $aria_req . $html_req  . ' /></div>'
			)
		),
		'comment_field' => '<div><label for="comment">' . __('Comment', 'y4apk') . '</label><textarea rows="5" minlength="5" id="comment" name="comment"' . $aria_req . $html_req  . '></textarea></div>',
		'submit_button' => '<button type="submit" class="btn" style="border-radius: 8px;">' . __('Post Comment', 'y4apk') . '</button>',
		'cancel_reply_link' => __('Cancel Reply', 'y4apk'),
        'title_reply' => '',
            'cancel_reply_before' => '<small class="cancel-reply-link">',
            'cancel_reply_after' => '</small>'
        );
        comment_form($args);
        if(have_comments()) :
	?>
	<h3 class="section-title">Comments (<?php comments_number('0', '1', '%'); ?>)</h3>
    <?php if (get_comment_pages_count() > 1 && get_option('page_comments')) : ?>
    <nav class="comment-navigation">
        <div class="nav-previous"><?php previous_comments_link('&larr; Older Comments'); ?></div>
        <div class="nav-next"><?php next_comments_link('Newer Comments &rarr;'); ?></div>
    </nav>
    <?php endif; ?>
    <ul class="comments">
        <?php wp_list_comments(['type' => 'comment', 'callback' => 'y4apk_comments']); ?>
    </ul>
    <?php if (get_comment_pages_count() > 1 && get_option('page_comments')) : ?>
    <nav class="comment-navigation">
        <div class="nav-previous"><?php previous_comments_link('&larr; Older Comments'); ?></div>
        <div class="nav-next"><?php next_comments_link('Newer Comments &rarr;'); ?></div>
    </nav>
    <?php endif;  endif;?>
</div>
<?php endif; ?>
<script>
const commentForm = document.getElementById('commentform');
/* Remove No validate for front end form validation (Dont't remove)*/
if (commentform) {
	commentform.removeAttribute('novalidate');
}
</script>