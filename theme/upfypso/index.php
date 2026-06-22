<?php

get_header();

?>

<main class="container">

<?php

if(have_posts()):

while(have_posts()):

the_post();

the_title('<h2>','</h2>');

the_excerpt();

endwhile;

endif;

?>

</main>

<?php

get_footer();

?>
