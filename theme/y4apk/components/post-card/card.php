<article class="post">
    <div class="cont">
        <div class="xsmf meta muted">
            <time class="date" datetime="<?php echo get_the_modified_time('c');?>"><?php echo get_the_modified_time('d-m-Y, H:i'); ?></time>
        </div>
        <h2 class="lgf title">
            <a class="noline" href="<?php the_permalink(); ?>">
            <span><?php the_title(); ?></span>
            </a>
        </h2>
    </div>
</article>