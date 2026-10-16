<?php
$app_name = get_post_meta( get_the_ID(), 'wp_title_GP', true );
$app_name = $app_name ?: get_the_title();
$post_categories = get_the_category(get_the_ID());

$parent_cat_ID = null;
foreach ($post_categories as $cat) {
    if ($cat->category_parent == 0) {
        $parent_cat_ID = $cat->term_id;
        break;
    }
}

$child_cats = get_categories(array('parent' => $parent_cat_ID, 'hide_empty' => false));
?>
<div class="f-app-main-head">
    <?php if($child_cats) : foreach($child_cats as $cat) : if (in_category($cat->term_id)) : ?>
    <a href="<?php echo get_category_link( $cat->term_id ); ?>" class="f-app-back" style="display:none;">
        <svg width="48" height="20">
            <use xlink:href="#i__back"></use>
        </svg>
    </a>
    <?php break; endif; endforeach; endif; ?>
    <div class="share-drop dropdown">
        <button class="share-drop-btn" data-toggle="dropdown">
            <svg width="24" height="24">
                <use xlink:href="#i__share"></use>
            </svg>
        </button>
        <div class="dropdown-menu share-drop-box" style="display:none;">
            <div class="share-list">
                <a class="share-btn share-fb" href="https://www.facebook.com/sharer.php?src=sp&amp;u=<?php the_permalink(); ?>&amp;title=<?php the_title(); ?>" target="_blank" rel="noopener nofollow" title="Share on Facebook" aria-label="Share on Facebook" onclick="minWin(this.href); return false;">
                    <svg width="40" height="40">
                        <use xlink:href="#i__facebook"></use>
                    </svg>
                </a>
                <a class="share-btn share-tw" href="https://twitter.com/intent/tweet?text=<?php the_title(); ?>&amp;url=<?php the_permalink(); ?>" target="_blank" rel="noopener nofollow" title="Share on Twitter" aria-label="Share on Twitter" onclick="minWin(this.href); return false;">
                    <svg width="40" height="40">
                        <use xlink:href="#i__twitter"></use>
                    </svg>
                </a>
                <a class="share-btn share-wa" href="https://api.whatsapp.com/send?text=<?php the_title(); ?>&nbsp;<?php the_permalink(); ?>" target="_blank" rel="noopener nofollow" title="Share on WhatsApp" aria-label="Share on WhatsApp" onclick="minWin(this.href); return false;">
                    <svg width="40" height="40">
                        <use xlink:href="#i__whatsapp"></use>
                    </svg>
                </a>
                <a class="share-btn share-tg" href="https://t.me/share/url?url=<?php the_permalink(); ?>&amp;text=<?php the_title(); ?>" target="_blank" rel="noopener nofollow" title="Share on Telegram" aria-label="Share on Telegram" onclick="minWin(this.href); return false;">
                    <svg width="40" height="40">
                        <use xlink:href="#i__telegram"></use>
                    </svg>
                </a>
            </div>
        </div>
    </div>
    <?php if(function_exists('breadcrumbsX')) breadcrumbsX(); ?>
</div>